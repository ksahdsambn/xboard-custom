<?php

namespace Plugin\WalletCenter\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoRenewRecord extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;
    public const STATUS_SKIPPED = 3;

    public static array $statusMap = [
        self::STATUS_PENDING => 'pending',
        self::STATUS_SUCCESS => 'success',
        self::STATUS_FAILED => 'failed',
        self::STATUS_SKIPPED => 'skipped',
    ];

    protected $table = 'wallet_center_auto_renew_records';

    protected $guarded = ['id'];

    protected $appends = [
        'status_label',
        'fund_activity_type',
        'reason_message',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'executed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function statusLabel(int $status): string
    {
        return self::$statusMap[$status] ?? 'unknown';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusLabel((int) ($this->attributes['status'] ?? self::STATUS_PENDING));
    }

    public function getFundActivityTypeAttribute(): string
    {
        return 'auto_renew_execution';
    }

    public function getReasonMessageAttribute(): ?string
    {
        return match ($this->reason) {
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
            default => null,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(AutoRenewSetting::class, 'setting_id', 'id');
    }
}
