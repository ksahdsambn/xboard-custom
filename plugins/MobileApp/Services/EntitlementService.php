<?php

namespace Plugin\MobileApp\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\UserService;
use DateTimeInterface;
use Plugin\MobileApp\Models\EntitlementProjection;
use Plugin\MobileApp\Models\PurchaseToken;

final class EntitlementService
{
    public const PLAN_ID_PREFIX = 'mobile-app:plan:v1:';

    public const IGNORED_CLIENT_CLAIMS = [
        'planId',
        'groupId',
        'price',
        'duration',
        'expiresAt',
        'trafficLimit',
        'resetAt',
        'expiresAtEpochMs',
        'remainingTrafficBytes',
        'plan_id',
        'group_id',
        'transfer_enable',
        'expired_at',
        'next_reset_at',
    ];

    public const STATUSES = ['maintenance', 'banned', 'none', 'expired', 'exhausted', 'active'];
    public const SOURCES = ['web', 'play', 'none'];

    public function __construct(private readonly UserService $users)
    {
    }

    public static function stripClientClaims(array $input): array
    {
        foreach (self::IGNORED_CLIENT_CLAIMS as $key) {
            unset($input[$key]);
        }
        return $input;
    }

    public static function opaquePlanId(int|string $planId): string
    {
        return hash('sha256', self::PLAN_ID_PREFIX . $planId);
    }

    public function forUser(User $user, array $clientClaims = []): array
    {
        $clientClaims = self::stripClientClaims($clientClaims);
        unset($clientClaims);
        $now = time();
        $projection = $this->activePlayProjection($user, $now);
        $webGrant = (int) ($user->plan_id ?? 0) > 0;
        $playGrant = $projection !== null;
        $source = $playGrant ? 'play' : ($webGrant ? 'web' : 'none');

        $transfer = (int) ($user->transfer_enable ?? 0);
        $used = $user->getTotalUsedTraffic();
        $remaining = $user->getRemainingTraffic();

        $expiry = $this->laterExpiry(
            $webGrant,
            $webGrant ? $user->expired_at : null,
            $playGrant,
            $playGrant ? $projection->expire_at : null
        );

        if ($this->isMaintenance()) {
            $status = 'maintenance';
            $denial = 'SERVICE_MAINTENANCE';
        } elseif ($user->banned) {
            $status = 'banned';
            $denial = 'AUTH_ACCOUNT_BANNED';
        } elseif (!$webGrant && !$playGrant) {
            $status = 'none';
            $denial = 'ENTITLEMENT_NONE';
        } elseif ($expiry !== null && (int) $expiry <= $now) {
            $status = 'expired';
            $denial = 'ENTITLEMENT_EXPIRED';
        } elseif ($remaining <= 0) {
            $status = 'exhausted';
            $denial = 'ENTITLEMENT_EXHAUSTED';
        } else {
            $status = 'active';
            $denial = null;
        }

        $plan = $this->resolvePlan($user, $projection);
        $resetAt = $this->resetAtEpochMs($user);
        $daysUntilReset = $this->users->getResetDay($user);
        $walletBlock = $this->walletAutoRenewBlockReason($user);

        return [
            'connectAllowed' => $status === 'active',
            'source' => $source,
            'playManaged' => $source === 'play',
            'walletAutoRenewBlocked' => $walletBlock !== null,
            'walletAutoRenewBlockReason' => $walletBlock,
            'status' => $status,
            'expiresAtEpochMs' => $expiry === null ? null : ((int) $expiry * 1000),
            'remainingTrafficBytes' => $remaining,
            'usedTrafficBytes' => $used,
            'transferEnableBytes' => $transfer,
            'resetAtEpochMs' => $resetAt,
            'daysUntilReset' => $daysUntilReset,
            'opaquePlanId' => $plan !== null ? self::opaquePlanId((int) $plan->id) : ($user->plan_id ? self::opaquePlanId((int) $user->plan_id) : null),
            'planName' => $plan?->name,
            'denialCode' => $denial,
        ];
    }

    public function walletAutoRenewBlockReason(User $user): ?string
    {
        $now = time();
        if ($this->activePlayProjection($user, $now) !== null) {
            return 'play_managed_entitlement';
        }
        $hold = PurchaseToken::query()
            ->where('user_id', $user->id)
            ->where('platform', PlayPurchaseService::PLATFORM)
            ->where('play_status', 'account_hold')
            ->exists();
        if ($hold) {
            return 'play_account_hold';
        }
        return null;
    }

    public function laterExpiry(bool $webGrant, mixed $webExpiry, bool $playGrant, mixed $playExpiry): ?int
    {
        $never = false;
        $finite = [];
        if ($webGrant) {
            if ($webExpiry === null) {
                $never = true;
            } else {
                $finite[] = (int) $webExpiry;
            }
        }
        if ($playGrant) {
            if ($playExpiry === null) {
                $never = true;
            } else {
                $finite[] = (int) $playExpiry;
            }
        }
        if ($never) {
            return null;
        }
        if ($finite === []) {
            return null;
        }
        return max($finite);
    }

    private function isMaintenance(): bool
    {
        return (bool) (new StartupConfigService())->settings()->maintenance;
    }

    private function activePlayProjection(User $user, int $now): ?EntitlementProjection
    {
        $rows = EntitlementProjection::query()
            ->where('user_id', $user->id)
            ->where('source', 'play')
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            if ($row->expire_at === null || (int) $row->expire_at > $now) {
                return $row;
            }
        }
        return null;
    }

    private function resolvePlan(User $user, ?EntitlementProjection $projection): ?Plan
    {
        $planId = $user->plan_id ?? $projection?->plan_id;
        if ($planId === null) {
            return null;
        }
        return Plan::query()->find($planId);
    }

    private function resetAtEpochMs(User $user): ?int
    {
        $raw = $user->next_reset_at;
        if ($raw instanceof DateTimeInterface) {
            $ts = $raw->getTimestamp();
            return $ts > 0 ? $ts * 1000 : null;
        }
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw * 1000;
        }
        return null;
    }
}
