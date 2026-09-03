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
            'user_not_found' => '自动续费用户不存在。',
            'user_banned' => '账户已被封禁，无法自动续费。',
            'plan_not_found' => '自动续费需要有效的订阅套餐。',
            'subscription_not_active' => '自动续费仅适用于有效订阅。',
            'onetime_subscription_not_supported' => '一次性订阅不支持自动续费。',
            'plan_not_renewable' => '当前套餐不允许续费。',
            'period_not_resolved' => '无法解析当前订阅周期。',
            'period_price_not_available' => '无法解析当前续费金额。',
            'pending_order_exists' => '存在未完成的核心订单，已跳过自动续费。',
            'insufficient_balance' => '余额不足，自动续费未执行。',
            'runtime_error' => '自动续费执行出错。',
            'disabled_by_user' => '已关闭自动续费。',
            'renewed' => '自动续费已通过官方订单开通完成。',
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
