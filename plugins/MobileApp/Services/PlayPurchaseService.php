<?php

namespace Plugin\MobileApp\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Plugin\MobileApp\Adapters\PlayDeveloperAdapter;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Models\AccountLink;
use Plugin\MobileApp\Models\PlayProduct;
use Plugin\MobileApp\Models\PurchaseToken;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobileRequestId;

final class PlayPurchaseService
{
    public const PLATFORM = 'google_play';

    public const PACKAGE = PlayDeveloperAdapter::PACKAGE;

    public const ACKNOWLEDGE_WINDOW_SECONDS = 259200;

    public const GRANTABLE = ['purchased', 'grace', 'restored'];

    public function __construct(
        private readonly PlayDeveloperAdapter $developer,
        private readonly PlanService $plans
    ) {
    }

    public function submit(User $user, array $input): array
    {
        return $this->ingest($user, $input, false);
    }

    public function restore(User $user, array $input): array
    {
        $result = $this->ingest($user, $input, true);
        return [
            'restored' => in_array($result['playStatus'], self::GRANTABLE, true),
        ];
    }

    private function ingest(User $user, array $input, bool $restore): array
    {
        $input = EntitlementService::stripClientClaims($input);
        unset($input['price'], $input['duration'], $input['expiresAt'], $input['entitlement'], $input['planId']);
        $productId = trim((string) ($input['productId'] ?? $input['playProductId'] ?? ''));
        $token = (string) ($input['purchaseToken'] ?? '');
        $obfuscated = trim((string) ($input['obfuscatedAccountId'] ?? $input['obfuscated_account_id'] ?? ''));
        if ($token === '' || !$this->validObfuscatedAccount($obfuscated, $user)) {
            throw new MobileApiException('PURCHASE_INVALID', 400);
        }
        $hash = hash('sha256', $token);
        $requestId = $this->requestId($input);
        $environment = \Plugin\MobileApp\Adapters\PlanAdapter::playEnvironment();

        $result = DB::transaction(function () use ($user, $productId, $token, $hash, $obfuscated, $requestId, $environment, $restore): array {
            $row = PurchaseToken::query()
                ->where('platform', self::PLATFORM)
                ->where('purchase_token_hash', $hash)
                ->lockForUpdate()
                ->first();
            if ($row instanceof PurchaseToken && (int) $row->user_id !== (int) $user->id) {
                throw new MobileApiException('PURCHASE_DUPLICATE', 409);
            }
            if (!$row instanceof PurchaseToken) {
                try {
                    $row = PurchaseToken::query()->create([
                        'user_id' => $user->id,
                        'platform' => self::PLATFORM,
                        'purchase_token_hash' => $hash,
                        'product_id' => $productId !== '' ? $productId : 'unknown',
                        'package_name' => self::PACKAGE,
                        'environment' => $environment,
                        'play_status' => 'received',
                        'acknowledged' => false,
                        'obfuscated_account_id' => $obfuscated,
                        'external_subscription_id' => null,
                        'request_id' => $requestId,
                    ]);
                } catch (UniqueConstraintViolationException|\Illuminate\Database\QueryException $exception) {
                    if ($exception instanceof \Illuminate\Database\QueryException
                        && !($exception instanceof UniqueConstraintViolationException)
                    ) {
                        $message = $exception->getMessage();
                        if (!str_contains($message, 'UNIQUE') && !str_contains(strtolower($message), 'unique') && (string) $exception->getCode() !== '23000') {
                            throw $exception;
                        }
                    }
                    $row = PurchaseToken::query()
                        ->where('platform', self::PLATFORM)
                        ->where('purchase_token_hash', $hash)
                        ->lockForUpdate()
                        ->first();
                    if (!$row instanceof PurchaseToken) {
                        throw new MobileApiException('PURCHASE_DUPLICATE', 409);
                    }
                    if ((int) $row->user_id !== (int) $user->id) {
                        throw new MobileApiException('PURCHASE_DUPLICATE', 409);
                    }
                }
            }

            $snapshot = $this->developer->getSubscription(self::PACKAGE, $token);
            if ($snapshot === null
                || ($snapshot['packageName'] ?? '') !== self::PACKAGE
                || (($apiProduct = (string) ($snapshot['productId'] ?? '')) !== '' && $productId !== '' && $apiProduct !== $productId)
                || !$this->mappedProduct((string) ($snapshot['productId'] ?? ''), $environment)
            ) {
                $this->markInvalid($row, 'PURCHASE_INVALID');
                return [
                    'ledgerId' => (int) $row->id,
                    'playStatus' => 'invalid',
                    'errorCode' => 'PURCHASE_INVALID',
                ];
            }
            $apiProduct = (string) $snapshot['productId'];
            $this->bindAccount($user, $obfuscated, $environment, $requestId);

            $status = (string) $snapshot['playStatus'];
            $row->product_id = $apiProduct;
            $row->package_name = self::PACKAGE;
            $row->play_status = $status;
            $row->is_renewal = (bool) ($snapshot['isRenewal'] ?? false);
            $row->obfuscated_account_id = $obfuscated;
            $row->external_subscription_id = (string) ($snapshot['externalSubscriptionId'] ?? '') ?: null;
            $row->verified_at = $row->verified_at ?? now();
            $row->request_id = $requestId;
            $row->last_error = null;

            if (in_array($status, self::GRANTABLE, true) && $row->granted_at === null) {
                $row->granted_at = now();
            }

            $verifiedTs = null;
            if ($row->verified_at instanceof \DateTimeInterface) {
                $verifiedTs = $row->verified_at->getTimestamp();
            } elseif ($row->verified_at !== null) {
                $verifiedTs = strtotime((string) $row->verified_at) ?: null;
            }
            $withinWindow = $verifiedTs === null || (time() - (int) $verifiedTs) <= self::ACKNOWLEDGE_WINDOW_SECONDS;
            $needsFirstAck = $status === 'purchased'
                && !((bool) $row->is_renewal)
                && !((bool) $row->acknowledged)
                && ($snapshot['acknowledgementState'] ?? '') === 'pending'
                && $row->granted_at !== null
                && $withinWindow;
            if ($needsFirstAck) {
                $this->developer->acknowledge(self::PACKAGE, $apiProduct, $token);
                $row->acknowledged = true;
                $row->acknowledged_at = now();
            }

            $row->save();
            (new EntitlementProjectionService())->project($user, $row, $snapshot);
            MobileLogRedactor::error('play_purchase', [
                'playStatus' => $status,
                'restore' => $restore,
                'ledgerId' => $row->id,
                'tokenHashPrefix' => substr($hash, 0, 8),
            ]);

            return [
                'ledgerId' => (int) $row->id,
                'playStatus' => $status,
            ];
        });
        if (($result['errorCode'] ?? null) === 'PURCHASE_INVALID') {
            throw new MobileApiException('PURCHASE_INVALID', 400);
        }
        if ($result['playStatus'] === 'pending') {
            throw new MobileApiException('PURCHASE_PENDING', 409);
        }
        return $result;
    }

    private function mappedProduct(string $productId, string $environment): bool
    {
        $available = $this->plans->getAvailablePlans()->keyBy(fn (Plan $plan): int => (int) $plan->id);
        $row = PlayProduct::query()
            ->where('enabled', true)
            ->where('environment', $environment)
            ->where('package_name', self::PACKAGE)
            ->where('product_id', $productId)
            ->first();
        if (!$row instanceof PlayProduct) {
            return false;
        }
        return $available->get((int) $row->xboard_plan_id) instanceof Plan;
    }

    private function bindAccount(User $user, string $obfuscated, string $environment, string $requestId): void
    {
        $link = AccountLink::query()
            ->where('platform', self::PLATFORM)
            ->where('obfuscated_account_id', $obfuscated)
            ->lockForUpdate()
            ->first();
        if ($link instanceof AccountLink) {
            if ((int) $link->user_id !== (int) $user->id && $link->revoked_at === null) {
                throw new MobileApiException('PURCHASE_DUPLICATE', 409);
            }
            if ((int) $link->user_id !== (int) $user->id && $link->revoked_at !== null) {
                $link->user_id = $user->id;
                $link->revoked_at = null;
                $link->status = 'active';
                $link->environment = $environment;
                $link->request_id = $requestId;
                $link->save();
            }
            return;
        }
        AccountLink::query()->create([
            'user_id' => $user->id,
            'platform' => self::PLATFORM,
            'obfuscated_account_id' => $obfuscated,
            'environment' => $environment,
            'status' => 'active',
            'request_id' => $requestId,
        ]);
    }

    public function applyRecheckedSnapshot(PurchaseToken $row, array $snapshot): array
    {
        $status = (string) ($snapshot['playStatus'] ?? '');
        $digest = hash('sha256', (string) $row->purchase_token_hash . '|' . $status . '|' . (string) ($snapshot['expiryTime'] ?? ''));
        $previous = (string) ($row->last_applied_digest ?? '');
        $changed = $digest !== $previous;
        $row->play_status = $status;
        if (($snapshot['productId'] ?? '') !== '') {
            $row->product_id = (string) $snapshot['productId'];
        }
        if (($snapshot['packageName'] ?? '') !== '') {
            $row->package_name = (string) $snapshot['packageName'];
        }
        $ext = (string) ($snapshot['externalSubscriptionId'] ?? '');
        if ($ext !== '') {
            $conflict = PurchaseToken::query()
                ->where('platform', self::PLATFORM)
                ->where('external_subscription_id', $ext)
                ->where('id', '!=', $row->id)
                ->exists();
            if (!$conflict) {
                $row->external_subscription_id = $ext;
            }
        }
        $row->is_renewal = (bool) ($snapshot['isRenewal'] ?? $row->is_renewal);
        $row->verified_at = now();
        $row->last_applied_digest = $digest;
        $row->last_error = null;
        if (in_array($status, self::GRANTABLE, true) && $row->granted_at === null) {
            $row->granted_at = now();
        }
        $row->save();
        $owner = User::query()->whereKey($row->user_id)->lockForUpdate()->first();
        if ($owner instanceof User) {
            (new EntitlementProjectionService())->project($owner, $row, $snapshot);
        }
        MobileLogRedactor::error('play_rtdn_recheck', [
            'playStatus' => $status,
            'ledgerId' => $row->id,
            'changed' => $changed,
            'tokenHashPrefix' => substr((string) $row->purchase_token_hash, 0, 8),
        ]);
        return [
            'changed' => $changed,
            'playStatus' => $status,
            'digest' => $digest,
        ];
    }

    public function findByTokenHash(string $hash): ?PurchaseToken
    {
        return PurchaseToken::query()
            ->where('platform', self::PLATFORM)
            ->where('purchase_token_hash', $hash)
            ->lockForUpdate()
            ->first();
    }

    private function markInvalid(PurchaseToken $row, string $code): void
    {
        $row->play_status = 'invalid';
        $row->last_error = $code;
        $row->save();
    }

    private function validObfuscatedAccount(string $value, User $user): bool
    {
        if (!preg_match('/^[A-Za-z0-9._-]{8,128}$/', $value)) {
            return false;
        }
        if (str_contains($value, '@') || $value === (string) $user->id || $value === (string) $user->email) {
            return false;
        }
        return true;
    }

    private function requestId(array $input): string
    {
        $requestId = trim((string) ($input['request_id'] ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }
        try {
            return MobileRequestId::resolve();
        } catch (\Throwable) {
            return (string) \Illuminate\Support\Str::uuid();
        }
    }
}
