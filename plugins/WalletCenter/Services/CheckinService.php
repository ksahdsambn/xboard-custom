<?php

namespace Plugin\WalletCenter\Services;

use App\Exceptions\ApiException;
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
            'can_claim' => !$todayRecord && $rewardRange['valid'] && !$user->banned,
            'today_record' => $todayRecord,
            'latest_record' => $latestRecord,
            'reward_range' => $rewardRange,
            'server_date' => $this->getClaimDate(),
            'notice' => $this->getNotice(),
            'streak_days' => $this->getStreakDays($user->id),
            'banned' => (bool) $user->banned,
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

    public function paginateHistoryForUser(User $user, int $page = 1, int $perPage = 10): array
    {
        $query = CheckinLog::query()->where('user_id', $user->id);
        $total = (int) $query->count();
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $records = $query->orderByDesc('claim_date')->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
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

            if ($lockedUser->banned) {
                throw new ApiException('封禁用户不能参与签到。');
            }

            $existingRecord = $this->findSuccessfulRecordByDate($lockedUser->id, $claimDate, true);
            if ($existingRecord) {
                return [
                    'claimed' => false,
                    'record' => $existingRecord,
                    'balance' => (int) ($lockedUser->balance ?? 0),
                    'reward_range' => $rewardRange,
                    'claim_date' => $claimDate,
                    'notice' => $this->getNotice(),
                    'streak_days' => $this->getStreakDays($lockedUser->id),
                ];
            }

            $rewardAmount = $rewardRange['min'] === $rewardRange['max']
                ? $rewardRange['min']
                : random_int($rewardRange['min'], $rewardRange['max']);

            $balanceBefore = (int) ($lockedUser->balance ?? 0);

            try {
                $record = CheckinLog::query()->create([
                    'user_id' => $lockedUser->id,
                    'claim_date' => $claimDate,
                    'reward_amount' => $rewardAmount,
                    'status' => 'success',
                    'meta' => $this->buildMeta($requestMeta, $balanceBefore, $balanceBefore + $rewardAmount),
                ]);
            } catch (\Illuminate\Database\QueryException $exception) {
                if (!str_contains(strtolower($exception->getMessage()), 'unique')
                    && !str_contains($exception->getMessage(), 'wallet_center_checkin_user_date_unique')) {
                    throw $exception;
                }

                $existingRecord = $this->findSuccessfulRecordByDate($lockedUser->id, $claimDate, true);

                return [
                    'claimed' => false,
                    'record' => $existingRecord,
                    'balance' => $balanceBefore,
                    'reward_range' => $rewardRange,
                    'claim_date' => $claimDate,
                    'notice' => $this->getNotice(),
                    'streak_days' => $this->getStreakDays($lockedUser->id),
                ];
            }

            if (!$this->userService->addBalance($lockedUser->id, $rewardAmount)) {
                throw new \RuntimeException('WalletCenter checkin reward credit failed.');
            }

            $lockedUser->refresh();
            $record->meta = $this->buildMeta($requestMeta, $balanceBefore, (int) ($lockedUser->balance ?? 0));
            $record->save();

            return [
                'claimed' => true,
                'record' => $record,
                'reward_amount' => $rewardAmount,
                'balance' => (int) ($lockedUser->balance ?? 0),
                'reward_range' => $rewardRange,
                'claim_date' => $claimDate,
                'notice' => $this->getNotice(),
                'streak_days' => $this->getStreakDays($lockedUser->id),
            ];
        }, 3);
    }

    public function getNotice(): string
    {
        $notice = trim((string) ($this->configService->getConfig()['checkin_notice'] ?? ''));

        return $notice !== '' ? $notice : '每天可签到一次，奖励将立即发放到账户余额。';
    }

    public function getStreakDays(int $userId): int
    {
        $dates = CheckinLog::query()
            ->where('user_id', $userId)
            ->where('status', 'success')
            ->orderByDesc('claim_date')
            ->limit(60)
            ->pluck('claim_date')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->unique()
            ->values();

        $streak = 0;
        $today = $this->getClaimDate();
        $cursor = $dates->contains($today)
            ? Carbon::parse($today)
            : Carbon::parse($today)->subDay();
        foreach ($dates as $date) {
            if ($date !== $cursor->toDateString()) {
                break;
            }
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
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
        return Carbon::now(config('app.timezone'))->toDateString();
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
