<?php

namespace Plugin\MobileApp\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Plugin\MobileApp\Adapters\PlanAdapter;
use Plugin\MobileApp\Models\EntitlementProjection;
use Plugin\MobileApp\Models\PlayProduct;
use Plugin\MobileApp\Models\PurchaseToken;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobileRequestId;

final class EntitlementProjectionService
{
    public const SOURCE = 'play';

    public const GRANTABLE = ['purchased', 'grace', 'restored', 'canceled'];

    public function project(User $user, PurchaseToken $ledger, array $snapshot): EntitlementProjection
    {
        return DB::transaction(function () use ($user, $ledger, $snapshot): EntitlementProjection {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$locked instanceof User) {
                throw new \RuntimeException('user missing for play projection');
            }
            $status = (string) ($snapshot['playStatus'] ?? $ledger->play_status ?? '');
            $playExpire = $this->parseExpiry($snapshot['expiryTime'] ?? null);
            $grantable = in_array($status, self::GRANTABLE, true) && ($playExpire === null || $playExpire > time());
            $idempotency = 'play:' . (int) $ledger->id . ':' . $status . ':' . ($playExpire ?? 'never');

            $existing = EntitlementProjection::query()
                ->where('idempotency_key', $idempotency)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof EntitlementProjection) {
                if ($existing->status === 'active') {
                    $baseline = $this->baselineFor($locked);
                    $activePlayExpire = EntitlementProjection::query()
                        ->where('user_id', $locked->id)
                        ->where('source', self::SOURCE)
                        ->where('status', 'active')
                        ->max('expire_at');
                    $this->captureWebExtension(
                        $locked,
                        $baseline,
                        $activePlayExpire !== null ? (int) $activePlayExpire : null
                    );
                    $plan = $existing->plan_id ? Plan::query()->find($existing->plan_id) : null;
                    $this->applyUserState(
                        $locked,
                        $baseline,
                        $existing,
                        $plan instanceof Plan ? $plan : null,
                        true,
                        $existing->expire_at !== null ? (int) $existing->expire_at : $playExpire,
                        (int) ($existing->traffic_bytes ?? 0)
                    );
                }
                return $existing;
            }

            $baseline = $this->baselineFor($locked);
            $activePlayExpire = EntitlementProjection::query()
                ->where('user_id', $locked->id)
                ->where('source', self::SOURCE)
                ->where('status', 'active')
                ->max('expire_at');
            $this->captureWebExtension(
                $locked,
                $baseline,
                $activePlayExpire !== null ? (int) $activePlayExpire : null
            );

            $planId = $this->mappedPlanId((string) $ledger->product_id, (string) $ledger->environment);
            $plan = $planId !== null ? Plan::query()->find($planId) : null;
            $playTraffic = $plan instanceof Plan ? $this->planTrafficBytes($plan) : 0;

            try {
                $row = EntitlementProjection::query()->create([
                    'user_id' => $locked->id,
                    'source' => self::SOURCE,
                    'purchase_token_id' => $ledger->id,
                    'plan_id' => $planId,
                    'expire_at' => $playExpire,
                    'traffic_bytes' => $playTraffic,
                    'idempotency_key' => $idempotency,
                    'request_id' => $this->requestId(),
                    'status' => $grantable ? 'active' : 'inactive',
                    'environment' => $ledger->environment ?: PlanAdapter::playEnvironment(),
                    'baseline_plan_id' => $baseline['plan_id'],
                    'baseline_expired_at' => $baseline['expired_at'],
                    'baseline_transfer_enable' => $baseline['transfer_enable'],
                    'baseline_group_id' => $baseline['group_id'],
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
                $row = EntitlementProjection::query()->where('idempotency_key', $idempotency)->first();
                if ($row instanceof EntitlementProjection) {
                    return $row;
                }
                throw $exception;
            }

            EntitlementProjection::query()
                ->where('user_id', $locked->id)
                ->where('source', self::SOURCE)
                ->where('purchase_token_id', $ledger->id)
                ->where('id', '!=', $row->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            $this->applyUserState($locked, $baseline, $row, $plan, $grantable, $playExpire, $playTraffic);
            MobileLogRedactor::error('play_projection', [
                'ledgerId' => $ledger->id,
                'playStatus' => $status,
                'grantable' => $grantable,
                'projectionId' => $row->id,
            ]);
            return $row;
        });
    }

    private function applyUserState(
        User $user,
        array $baseline,
        EntitlementProjection $row,
        ?Plan $plan,
        bool $grantable,
        ?int $playExpire,
        int $playTraffic
    ): void {
        $otherActive = EntitlementProjection::query()
            ->where('user_id', $user->id)
            ->where('source', self::SOURCE)
            ->where('status', 'active')
            ->where('id', '!=', $row->id)
            ->orderByDesc('id')
            ->get();

        if ($grantable) {
            $candidates = array_filter([
                $baseline['expired_at'],
                $playExpire,
                $this->intOrNull($user->expired_at),
            ], static fn ($value): bool => $value !== null);
            $mergedExpire = $candidates === [] ? $playExpire : max($candidates);
            $user->expired_at = $mergedExpire;
            if ((int) ($user->plan_id ?? 0) <= 0 && $plan instanceof Plan) {
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
            }
            $currentTraffic = (int) ($user->transfer_enable ?? 0);
            $user->transfer_enable = max($currentTraffic, (int) $baseline['transfer_enable'], $playTraffic);
            $user->save();
            return;
        }

        if ($otherActive->isNotEmpty()) {
            return;
        }
        $user->plan_id = $baseline['plan_id'];
        $user->group_id = $baseline['group_id'];
        $user->expired_at = $baseline['expired_at'];
        $user->transfer_enable = $baseline['transfer_enable'];
        $user->save();
    }

    private function baselineFor(User $user): array
    {
        $prior = EntitlementProjection::query()
            ->where('user_id', $user->id)
            ->where('source', self::SOURCE)
            ->orderBy('id')
            ->first();
        if ($prior instanceof EntitlementProjection) {
            return [
                'plan_id' => $prior->baseline_plan_id ? (int) $prior->baseline_plan_id : null,
                'expired_at' => $prior->baseline_expired_at !== null ? (int) $prior->baseline_expired_at : null,
                'transfer_enable' => (int) ($prior->baseline_transfer_enable ?? 0),
                'group_id' => $prior->baseline_group_id !== null ? (int) $prior->baseline_group_id : null,
            ];
        }
        $planId = (int) ($user->plan_id ?? 0);
        return [
            'plan_id' => $planId > 0 ? $planId : null,
            'expired_at' => $this->intOrNull($user->expired_at),
            'transfer_enable' => (int) ($user->transfer_enable ?? 0),
            'group_id' => $user->group_id !== null ? (int) $user->group_id : null,
        ];
    }

    private function captureWebExtension(User $user, array &$baseline, ?int $playExpire): void
    {
        $current = $this->intOrNull($user->expired_at);
        $ceiling = max((int) ($baseline['expired_at'] ?? 0), (int) ($playExpire ?? 0));
        if ($current !== null && $current > $ceiling) {
            $baseline['expired_at'] = $current;
            if ((int) ($user->plan_id ?? 0) > 0) {
                $baseline['plan_id'] = (int) $user->plan_id;
            }
            EntitlementProjection::query()
                ->where('user_id', $user->id)
                ->where('source', self::SOURCE)
                ->update([
                    'baseline_expired_at' => $baseline['expired_at'],
                    'baseline_plan_id' => $baseline['plan_id'],
                ]);
        }
    }

    private function mappedPlanId(string $productId, string $environment): ?int
    {
        $row = PlayProduct::query()
            ->where('enabled', true)
            ->where('environment', $environment !== '' ? $environment : PlanAdapter::playEnvironment())
            ->where('package_name', PlayPurchaseService::PACKAGE)
            ->where('product_id', $productId)
            ->first();
        if (!$row instanceof PlayProduct) {
            return null;
        }
        return (int) $row->xboard_plan_id;
    }

    private function planTrafficBytes(Plan $plan): int
    {
        $raw = (int) ($plan->transfer_enable ?? 0);
        if ($raw <= 0) {
            return 0;
        }
        if ($raw < 1024 * 1024) {
            return $raw * 1073741824;
        }
        return $raw;
    }

    private function parseExpiry(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;
            return $n > 0 ? $n : null;
        }
        $ts = strtotime((string) $value);
        return $ts !== false && $ts > 0 ? $ts : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $ts = $value->getTimestamp();
            return $ts > 0 ? $ts : null;
        }
        $n = (int) $value;
        return $n > 0 ? $n : null;
    }

    private function requestId(): string
    {
        try {
            return MobileRequestId::resolve();
        } catch (\Throwable) {
            return (string) \Illuminate\Support\Str::uuid();
        }
    }
}
