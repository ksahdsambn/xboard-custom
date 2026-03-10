<?php

namespace Plugin\WalletCenter\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\WalletCenter\Models\AutoRenewRecord;
use Plugin\WalletCenter\Models\AutoRenewSetting;

class AutoRenewService
{
    public const DEFAULT_SCAN_LIMIT = 100;
    public const SCHEDULE_OVERLAP_LOCK_MINUTES = 10;
    protected const SHORT_RETRY_MINUTES = 5;
    protected const LONG_RETRY_MINUTES = 30;

    public function __construct(
        protected WalletCenterConfigService $configService,
        protected UserService $userService
    ) {
    }

    public function getConfigSnapshot(User $user): array
    {
        $setting = $this->getSettingByUserId($user->id);
        $context = $this->resolveContext($user, $setting);
        $latestRecord = $this->getLatestRecordForUser($user->id);
        $snapshot = is_array(optional($setting)->snapshot) ? $setting->snapshot : [];
        $nextScanAt = optional($setting)->next_scan_at ?? $context['next_scan_at'];

        $result = [
            'config' => [
                'enabled' => (bool) optional($setting)->enabled,
                'renew_window_hours' => $context['renew_window_hours'],
                'next_scan_at' => (bool) optional($setting)->enabled
                    ? $this->formatDateTime($nextScanAt)
                    : null,
                'last_result' => $latestRecord
                    ? AutoRenewRecord::statusLabel((int) $latestRecord->status)
                    : ($setting->last_result ?? null),
                'last_result_at' => $latestRecord
                    ? $this->formatDateTime($latestRecord->executed_at)
                    : $this->formatDateTime(optional($setting)->last_result_at),
                'last_result_reason' => $latestRecord->reason ?? Arr::get($snapshot, 'latest_reason'),
            ],
            'subscription' => $context['subscription'],
            'latest_record' => $latestRecord,
        ];

        $result['subscription']['next_scan_at'] = (bool) optional($setting)->enabled
            ? $this->formatDateTime($nextScanAt)
            : null;

        return $result;
    }

    public function updateSetting(User $user, bool $enabled): array
    {
        DB::transaction(function () use ($user, $enabled): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser) {
                throw new ApiException('WalletCenter auto renew user does not exist.');
            }

            $setting = AutoRenewSetting::query()
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            if (!$setting) {
                $setting = new AutoRenewSetting([
                    'user_id' => $lockedUser->id,
                ]);
            }

            $context = $this->resolveContext($lockedUser, $setting);
            if ($enabled && !$context['can_enable']) {
                throw new ApiException($this->reasonToMessage($context['reason']));
            }

            $setting->enabled = $enabled;
            $setting->period = $context['period'];
            $setting->renew_window_hours = $context['renew_window_hours'];
            $setting->next_scan_at = $enabled ? $context['next_scan_at'] : null;
            $setting->last_result = $enabled ? ($setting->last_result ?: 'enabled') : 'disabled';
            $setting->last_result_at = now();
            $setting->snapshot = $this->buildSettingSnapshot(
                $context,
                null,
                $enabled ? null : 'disabled_by_user'
            );
            $setting->save();
        }, 3);

        return $this->getConfigSnapshot($user);
    }

    public function getHistoryForUser(User $user, int $limit = 20): Collection
    {
        return AutoRenewRecord::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminRecords(int $limit = 20): Collection
    {
        return AutoRenewRecord::query()
            ->with('user:id,email')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminSummary(): array
    {
        $statusCounts = [
            'pending' => AutoRenewRecord::query()->where('status', AutoRenewRecord::STATUS_PENDING)->count(),
            'success' => AutoRenewRecord::query()->where('status', AutoRenewRecord::STATUS_SUCCESS)->count(),
            'failed' => AutoRenewRecord::query()->where('status', AutoRenewRecord::STATUS_FAILED)->count(),
            'skipped' => AutoRenewRecord::query()->where('status', AutoRenewRecord::STATUS_SKIPPED)->count(),
        ];

        $latestRecord = AutoRenewRecord::query()
            ->with('user:id,email')
            ->orderByDesc('id')
            ->first();

        $latestFailure = AutoRenewRecord::query()
            ->with('user:id,email')
            ->whereIn('status', [
                AutoRenewRecord::STATUS_FAILED,
                AutoRenewRecord::STATUS_SKIPPED,
            ])
            ->orderByDesc('id')
            ->first();

        return [
            'enabled' => $this->configService->isFeatureEnabled('auto_renew'),
            'record_count' => array_sum($statusCounts),
            'status_counts' => $statusCounts,
            'renew_window_hours' => $this->getWindowHours(),
            'latest_record' => $latestRecord,
            'latest_failure' => $latestFailure,
        ];
    }

    public function getReasonMessage(?string $reason): string
    {
        return $this->reasonToMessage($reason);
    }

    public function scan(int $limit = self::DEFAULT_SCAN_LIMIT, bool $dueOnly = false): array
    {
        $query = AutoRenewSetting::query()
            ->where('enabled', true);

        if ($dueOnly) {
            $query->where(function ($builder): void {
                $builder->whereNull('next_scan_at')
                    ->orWhere('next_scan_at', '<=', now());
            });
        }

        $settings = $query
            ->orderByRaw('CASE WHEN next_scan_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('next_scan_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $summary = [
            'scanned' => $settings->count(),
            'not_due' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'processed_user_ids' => [],
        ];

        foreach ($settings as $setting) {
            $record = $this->processSetting($setting);
            if ($record instanceof AutoRenewRecord) {
                $summary['processed_user_ids'][] = $record->user_id;
                $summary[AutoRenewRecord::statusLabel((int) $record->status)]++;
                continue;
            }

            $summary['not_due']++;
        }

        $summary['processed_user_ids'] = array_values(array_unique($summary['processed_user_ids']));

        return $summary;
    }

    protected function processSetting(AutoRenewSetting $setting): ?AutoRenewRecord
    {
        try {
            return DB::transaction(function () use ($setting): ?AutoRenewRecord {
                $lockedSetting = AutoRenewSetting::query()
                    ->whereKey($setting->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSetting || !$lockedSetting->enabled) {
                    return null;
                }

                if ($lockedSetting->next_scan_at && $lockedSetting->next_scan_at->isFuture()) {
                    return null;
                }

                $lockedUser = User::query()
                    ->whereKey($lockedSetting->user_id)
                    ->lockForUpdate()
                    ->first();

                $context = $this->resolveContext($lockedUser, $lockedSetting);
                if (!$context['renewable']) {
                    $record = $this->createRecord(
                        $lockedSetting,
                        $context,
                        AutoRenewRecord::STATUS_FAILED,
                        $context['reason'],
                        [],
                        $this->resolveNextAttemptAt($context, $context['reason'])
                    );
                    $this->syncSetting($lockedSetting, $context, $record);

                    return $record;
                }

                if (!$context['is_due']) {
                    $this->syncSetting($lockedSetting, $context);

                    return null;
                }

                if ($context['pending_order']) {
                    $record = $this->createRecord(
                        $lockedSetting,
                        $context,
                        AutoRenewRecord::STATUS_SKIPPED,
                        'pending_order_exists',
                        [
                            'pending_order' => $context['pending_order'],
                        ],
                        $this->resolveNextAttemptAt($context, 'pending_order_exists')
                    );
                    $this->syncSetting($lockedSetting, $context, $record);

                    return $record;
                }

                if ($context['balance'] < $context['amount']) {
                    $record = $this->createRecord(
                        $lockedSetting,
                        $context,
                        AutoRenewRecord::STATUS_FAILED,
                        'insufficient_balance',
                        [
                            'balance_before' => $context['balance'],
                            'required_amount' => $context['amount'],
                        ],
                        $this->resolveNextAttemptAt($context, 'insufficient_balance')
                    );
                    $this->syncSetting($lockedSetting, $context, $record);

                    return $record;
                }

                $balanceBefore = $context['balance'];
                $expiredAtBefore = $context['expired_at'];

                if (!$this->userService->addBalance($lockedUser->id, -$context['amount'])) {
                    throw new \RuntimeException('WalletCenter auto renew balance debit failed.');
                }

                $lockedUser->refresh();

                $this->applyRenewal($lockedUser, $context['plan'], $context['period'], $expiredAtBefore);
                if (!$lockedUser->save()) {
                    throw new \RuntimeException('WalletCenter auto renew user save failed.');
                }

                $refreshedContext = $this->resolveContext($lockedUser, $lockedSetting);
                $record = $this->createRecord(
                    $lockedSetting,
                    $refreshedContext,
                    AutoRenewRecord::STATUS_SUCCESS,
                    'renewed',
                    [
                        'balance_before' => $balanceBefore,
                        'balance_after' => (int) ($lockedUser->balance ?? 0),
                        'expired_at_before' => $expiredAtBefore,
                        'expired_at_after' => (int) ($lockedUser->expired_at ?? 0),
                    ],
                    $refreshedContext['next_scan_at']
                );
                $this->syncSetting($lockedSetting, $refreshedContext, $record);

                return $record;
            }, 3);
        } catch (\Throwable $exception) {
            Log::error($exception);

            return $this->recordUnexpectedFailure($setting);
        }
    }

    protected function resolveContext(?User $user, ?AutoRenewSetting $setting = null): array
    {
        $windowHours = $this->getWindowHours();
        $now = now();
        $enabled = (bool) optional($setting)->enabled;

        if (!$user) {
            return $this->buildContext(
                enabled: $enabled,
                renewWindowHours: $windowHours,
                reason: 'user_not_found'
            );
        }

        $plan = $user->plan_id ? Plan::query()->find($user->plan_id) : null;
        $sourceOrder = $this->findSourceOrder($user, $setting);
        $period = $sourceOrder ? PlanService::getPeriodKey((string) $sourceOrder->period) : ($setting->period ?: null);
        $amount = $this->resolveAmount($plan, $period);
        $expiredAt = $this->normalizeTimestamp($user->expired_at);
        $dueAt = $expiredAt ? $this->createFromTimestamp($expiredAt)->subHours($windowHours) : null;
        $pendingOrder = $this->findPendingOrder($user->id);
        $subscriptionActive = $this->isFiniteActiveSubscription($user);
        $reason = $this->resolveReason($user, $plan, $period, $amount);
        $renewable = $reason === null;
        $isDue = $renewable && $dueAt && $dueAt->lessThanOrEqualTo($now);

        return $this->buildContext(
            enabled: $enabled,
            renewWindowHours: $windowHours,
            user: $user,
            plan: $plan,
            sourceOrder: $sourceOrder,
            pendingOrder: $pendingOrder,
            period: $period,
            amount: $amount,
            expiredAt: $expiredAt,
            dueAt: $dueAt,
            renewable: $renewable,
            subscriptionActive: $subscriptionActive,
            isDue: $isDue,
            canEnable: $renewable,
            reason: $reason
        );
    }

    protected function buildContext(
        bool $enabled,
        int $renewWindowHours,
        ?User $user = null,
        ?Plan $plan = null,
        ?Order $sourceOrder = null,
        ?Order $pendingOrder = null,
        ?string $period = null,
        int $amount = 0,
        ?int $expiredAt = null,
        ?Carbon $dueAt = null,
        bool $renewable = false,
        bool $subscriptionActive = false,
        bool $isDue = false,
        bool $canEnable = false,
        ?string $reason = null
    ): array {
        $pendingOrderSnapshot = $pendingOrder ? [
            'id' => $pendingOrder->id,
            'trade_no' => $pendingOrder->trade_no,
            'status' => $pendingOrder->status,
            'type' => $pendingOrder->type,
        ] : null;

        $sourceOrderSnapshot = $sourceOrder ? [
            'id' => $sourceOrder->id,
            'trade_no' => $sourceOrder->trade_no,
            'period' => $sourceOrder->period,
            'type' => $sourceOrder->type,
            'status' => $sourceOrder->status,
            'created_at' => $sourceOrder->created_at,
        ] : null;

        return [
            'enabled' => $enabled,
            'renew_window_hours' => $renewWindowHours,
            'user_id' => $user?->id,
            'plan' => $plan,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name,
            'period' => $period,
            'amount' => $amount,
            'balance' => (int) ($user->balance ?? 0),
            'expired_at' => $expiredAt,
            'due_at' => $dueAt,
            'next_scan_at' => $renewable ? $dueAt : null,
            'renewable' => $renewable,
            'subscription_active' => $subscriptionActive,
            'is_due' => $isDue,
            'can_enable' => $canEnable,
            'reason' => $reason,
            'pending_order' => $pendingOrderSnapshot,
            'source_order' => $sourceOrderSnapshot,
            'subscription' => [
                'active' => $subscriptionActive,
                'renewable' => $renewable,
                'can_enable' => $canEnable,
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'period' => $period,
                'amount' => $amount,
                'balance' => (int) ($user->balance ?? 0),
                'expired_at' => $expiredAt,
                'due_at' => $this->formatDateTime($dueAt),
                'next_scan_at' => $this->formatDateTime($renewable ? $dueAt : null),
                'pending_order' => $pendingOrderSnapshot,
                'source_order' => $sourceOrderSnapshot,
                'reason' => $reason,
                'reason_message' => $reason ? $this->reasonToMessage($reason) : null,
            ],
        ];
    }

    protected function syncSetting(AutoRenewSetting $setting, array $context, ?AutoRenewRecord $record = null): void
    {
        $latestReason = $record?->reason;

        $setting->period = $context['period'];
        $setting->renew_window_hours = $context['renew_window_hours'];
        $setting->next_scan_at = $context['enabled']
            ? ($record?->next_attempt_at ?? $context['next_scan_at'])
            : null;
        $setting->snapshot = $this->buildSettingSnapshot($context, $record, $latestReason);

        if ($record) {
            $setting->last_result = AutoRenewRecord::statusLabel((int) $record->status);
            $setting->last_result_at = $record->executed_at;
        }

        $setting->save();
    }

    protected function buildSettingSnapshot(array $context, ?AutoRenewRecord $record = null, ?string $latestReason = null): array
    {
        return array_filter([
            'source' => 'wallet_center_auto_renew',
            'plan_id' => $context['plan_id'],
            'plan_name' => $context['plan_name'],
            'period' => $context['period'],
            'amount' => $context['amount'],
            'balance' => $context['balance'],
            'expired_at' => $context['expired_at'],
            'due_at' => optional($context['due_at'])->timestamp,
            'renewable' => $context['renewable'],
            'subscription_active' => $context['subscription_active'],
            'pending_order' => $context['pending_order'],
            'source_order' => $context['source_order'],
            'latest_status' => $record ? AutoRenewRecord::statusLabel((int) $record->status) : null,
            'latest_reason' => $latestReason,
            'latest_executed_at' => $record ? $this->formatDateTime($record->executed_at) : null,
        ], static fn ($value) => $value !== null);
    }

    protected function createRecord(
        AutoRenewSetting $setting,
        array $context,
        int $status,
        string $reason,
        array $extraSnapshot = [],
        ?Carbon $nextAttemptAt = null
    ): AutoRenewRecord {
        return AutoRenewRecord::query()->create([
            'user_id' => $setting->user_id,
            'setting_id' => $setting->id,
            'amount' => $context['amount'],
            'status' => $status,
            'executed_at' => now(),
            'next_attempt_at' => $nextAttemptAt,
            'reason' => $reason,
            'snapshot' => array_replace_recursive(
                $this->buildSettingSnapshot($context, null, $reason),
                $extraSnapshot
            ),
        ]);
    }

    protected function recordUnexpectedFailure(AutoRenewSetting $setting): AutoRenewRecord
    {
        return DB::transaction(function () use ($setting): AutoRenewRecord {
            $lockedSetting = AutoRenewSetting::query()
                ->whereKey($setting->id)
                ->lockForUpdate()
                ->firstOrFail();

            $context = $this->resolveContext(User::query()->find($lockedSetting->user_id), $lockedSetting);
            $record = $this->createRecord(
                $lockedSetting,
                $context,
                AutoRenewRecord::STATUS_FAILED,
                'runtime_error',
                [],
                $this->resolveNextAttemptAt($context, 'runtime_error')
            );
            $this->syncSetting($lockedSetting, $context, $record);

            return $record;
        }, 3);
    }

    protected function applyRenewal(User $user, Plan $plan, string $period, int $expiredAtBefore): void
    {
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->speed_limit = $plan->speed_limit;
        $user->device_limit = $plan->device_limit;
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->expired_at = $this->calculateNextExpiredAt($period, $expiredAtBefore);
    }

    protected function calculateNextExpiredAt(string $period, int $expiredAtBefore): int
    {
        $periodKey = PlanService::getPeriodKey($period);
        $periods = Plan::getAvailablePeriods();
        $months = (int) ($periods[$periodKey]['value'] ?? 0);

        if ($months <= 0) {
            throw new \RuntimeException('WalletCenter auto renew period is invalid.');
        }

        return $this->createFromTimestamp(max($expiredAtBefore, time()))
            ->addMonths($months)
            ->timestamp;
    }

    protected function getLatestRecordForUser(int $userId): ?AutoRenewRecord
    {
        return AutoRenewRecord::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    protected function getSettingByUserId(int $userId): ?AutoRenewSetting
    {
        return AutoRenewSetting::query()
            ->where('user_id', $userId)
            ->first();
    }

    protected function findSourceOrder(User $user, ?AutoRenewSetting $setting = null): ?Order
    {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNotIn('period', [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC]);

        if ($user->plan_id) {
            $query->where('plan_id', $user->plan_id);
        }

        $order = $query
            ->orderByDesc('id')
            ->first();

        if ($order) {
            return $order;
        }

        if ($setting?->period) {
            return Order::query()
                ->where('user_id', $user->id)
                ->where('status', Order::STATUS_COMPLETED)
                ->where('period', $setting->period)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    protected function findPendingOrder(int $userId): ?Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->orderBy('id')
            ->first();
    }

    protected function resolveAmount(?Plan $plan, ?string $period): int
    {
        if (!$plan || !$period) {
            return 0;
        }

        $price = $plan->prices[$period] ?? null;
        if (!is_numeric($price)) {
            return 0;
        }

        return (int) round(((float) $price) * 100);
    }

    protected function isFiniteActiveSubscription(User $user): bool
    {
        return !$user->banned
            && $user->plan_id !== null
            && (int) ($user->transfer_enable ?? 0) > 0
            && $user->expired_at !== null
            && (int) $user->expired_at > time();
    }

    protected function resolveReason(User $user, ?Plan $plan, ?string $period, int $amount): ?string
    {
        if ($user->plan_id === null || !$plan) {
            return 'plan_not_found';
        }

        if ($user->expired_at === null) {
            return 'onetime_subscription_not_supported';
        }

        if (!$this->isFiniteActiveSubscription($user)) {
            return 'subscription_not_active';
        }

        if (!$plan->renew) {
            return 'plan_not_renewable';
        }

        if (!$period) {
            return 'period_not_resolved';
        }

        if ($amount <= 0) {
            return 'period_price_not_available';
        }

        return null;
    }

    public function getWindowHours(): int
    {
        $config = $this->configService->getConfig();
        $hours = $this->toInteger($config['auto_renew_window_hours'] ?? 24);

        return $hours > 0 ? $hours : 24;
    }

    protected function normalizeTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $timestamp = $this->toInteger($value);

        return $timestamp > 0 ? $timestamp : null;
    }

    protected function reasonToMessage(?string $reason): string
    {
        return match ($reason) {
            'user_not_found' => 'WalletCenter auto renew user does not exist.',
            'plan_not_found' => 'WalletCenter auto renew requires an active subscription plan.',
            'subscription_not_active' => 'WalletCenter auto renew is only available for active subscriptions.',
            'onetime_subscription_not_supported' => 'WalletCenter auto renew does not support one-time subscriptions.',
            'plan_not_renewable' => 'WalletCenter auto renew requires a renewable subscription plan.',
            'period_not_resolved' => 'WalletCenter auto renew could not resolve the current subscription period.',
            'period_price_not_available' => 'WalletCenter auto renew could not resolve the current renewal amount.',
            'pending_order_exists' => 'WalletCenter auto renew skipped because a core order is still pending.',
            'insufficient_balance' => 'WalletCenter auto renew failed because the balance is insufficient.',
            'runtime_error' => 'WalletCenter auto renew failed because of a runtime error.',
            'disabled_by_user' => 'WalletCenter auto renew has been disabled.',
            'renewed' => 'WalletCenter auto renew completed successfully.',
            default => 'WalletCenter auto renew is not available.',
        };
    }

    protected function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return null;
    }

    protected function createFromTimestamp(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, now()->getTimezone());
    }

    protected function toInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return 0;
    }

    protected function resolveNextAttemptAt(array $context, string $reason): ?Carbon
    {
        if (!$context['enabled']) {
            return null;
        }

        $now = now();

        return match ($reason) {
            'pending_order_exists', 'insufficient_balance' => $now->copy()->addMinutes(self::SHORT_RETRY_MINUTES),
            'runtime_error',
            'user_not_found',
            'plan_not_found',
            'subscription_not_active',
            'onetime_subscription_not_supported',
            'plan_not_renewable',
            'period_not_resolved',
            'period_price_not_available' => $now->copy()->addMinutes(self::LONG_RETRY_MINUTES),
            default => $context['next_scan_at'],
        };
    }
}
