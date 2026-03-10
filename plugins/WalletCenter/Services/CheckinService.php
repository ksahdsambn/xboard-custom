<?php

namespace Plugin\WalletCenter\Services;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Plugin\WalletCenter\Models\CheckinLog;

class CheckinService
{
    public function __construct(
        protected WalletCenterConfigService $configService,
        protected UserService $userService
    ) {
    }

    public function getStatusSnapshot(User $user): array
    {
        $todayRecord = $this->findTodaySuccessfulRecord($user->id);
        $latestRecord = CheckinLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->first();
        $rewardRange = $this->getRewardRangeSnapshot();

        return [
            'today_claimed' => (bool) $todayRecord,
            'can_claim' => !$todayRecord && $rewardRange['valid'],
            'today_record' => $todayRecord,
            'latest_record' => $latestRecord,
            'reward_range' => $rewardRange,
            'server_date' => $this->getClaimDate(),
        ];
    }

    public function getHistoryForUser(User $user, int $limit = 20): Collection
    {
        return CheckinLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminHistory(int $limit = 20): Collection
    {
        return CheckinLog::query()
            ->with('user:id,email')
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getAdminSummary(): array
    {
        $latestRecord = CheckinLog::query()
            ->with('user:id,email')
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->first();

        return [
            'enabled' => $this->configService->isFeatureEnabled('checkin'),
            'record_count' => CheckinLog::query()->count(),
            'today_success_count' => CheckinLog::query()
                ->whereDate('claim_date', $this->getClaimDate())
                ->where('status', 'success')
                ->count(),
            'reward_range' => $this->getRewardRangeSnapshot(),
            'latest_record' => $latestRecord,
        ];
    }

    public function claim(User $user, array $requestMeta = []): array
    {
        $rewardRange = $this->getValidatedRewardRange();
        $claimDate = $this->getClaimDate();

        return DB::transaction(function () use ($user, $requestMeta, $rewardRange, $claimDate): array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser) {
                throw new \RuntimeException('WalletCenter checkin user not found.');
            }

            $existingRecord = $this->findSuccessfulRecordByDate($lockedUser->id, $claimDate, true);
            if ($existingRecord) {
                return [
                    'claimed' => false,
                    'record' => $existingRecord,
                    'balance' => (int) ($lockedUser->balance ?? 0),
                    'reward_range' => $rewardRange,
                    'claim_date' => $claimDate,
                ];
            }

            $rewardAmount = $rewardRange['min'] === $rewardRange['max']
                ? $rewardRange['min']
                : random_int($rewardRange['min'], $rewardRange['max']);

            $balanceBefore = (int) ($lockedUser->balance ?? 0);
            if (!$this->userService->addBalance($lockedUser->id, $rewardAmount)) {
                throw new \RuntimeException('WalletCenter checkin reward credit failed.');
            }

            $lockedUser->refresh();

            $record = CheckinLog::query()->create([
                'user_id' => $lockedUser->id,
                'claim_date' => $claimDate,
                'reward_amount' => $rewardAmount,
                'status' => 'success',
                'meta' => $this->buildMeta($requestMeta, $balanceBefore, (int) ($lockedUser->balance ?? 0)),
            ]);

            return [
                'claimed' => true,
                'record' => $record,
                'reward_amount' => $rewardAmount,
                'balance' => (int) ($lockedUser->balance ?? 0),
                'reward_range' => $rewardRange,
                'claim_date' => $claimDate,
            ];
        }, 3);
    }

    public function getRewardRangeSnapshot(): array
    {
        $config = $this->configService->getConfig();
        $min = $this->toInteger($config['checkin_reward_min'] ?? 0);
        $max = $this->toInteger($config['checkin_reward_max'] ?? 0);

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min' => $min,
            'max' => $max,
            'valid' => $min > 0 && $max > 0,
        ];
    }

    protected function getValidatedRewardRange(): array
    {
        $range = $this->getRewardRangeSnapshot();
        if (!$range['valid']) {
            throw new \RuntimeException('WalletCenter checkin reward configuration is invalid.');
        }

        return $range;
    }

    protected function findTodaySuccessfulRecord(int $userId): ?CheckinLog
    {
        return $this->findSuccessfulRecordByDate($userId, $this->getClaimDate());
    }

    protected function findSuccessfulRecordByDate(int $userId, string $claimDate, bool $lock = false): ?CheckinLog
    {
        $query = CheckinLog::query()
            ->where('user_id', $userId)
            ->whereDate('claim_date', $claimDate)
            ->where('status', 'success');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->orderByDesc('id')
            ->first();
    }

    protected function getClaimDate(): string
    {
        return Carbon::now()->toDateString();
    }

    protected function buildMeta(array $requestMeta, int $balanceBefore, int $balanceAfter): array
    {
        $meta = [
            'source' => 'wallet_center_checkin',
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'request_ip' => $requestMeta['request_ip'] ?? null,
            'user_agent' => $requestMeta['user_agent'] ?? null,
        ];

        return array_filter($meta, static fn ($value) => $value !== null && $value !== '');
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
            return (int) trim($value);
        }

        return 0;
    }
}
