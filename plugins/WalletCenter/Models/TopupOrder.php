<?php

namespace Plugin\WalletCenter\Models;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TopupOrder extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_PAID = 2;
    public const STATUS_CANCELLED = 3;
    public const STATUS_EXPIRED = 4;

    public static array $statusMap = [
        self::STATUS_PENDING => 'pending',
        self::STATUS_PROCESSING => 'processing',
        self::STATUS_PAID => 'paid',
        self::STATUS_CANCELLED => 'cancelled',
        self::STATUS_EXPIRED => 'expired',
    ];

    protected $table = 'wallet_center_topup_orders';

    protected $guarded = ['id'];

    protected $appends = [
        'status_label',
        'fund_activity_type',
        'failure_reason',
        'failure_message',
    ];

    protected $casts = [
        'channel_snapshot' => 'array',
        'extra' => 'array',
        'paid_at' => 'datetime',
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
        return 'balance_topup';
    }

    public function getFailureReasonAttribute(): ?string
    {
        $extra = is_array($this->extra) ? $this->extra : [];
        if (!empty($extra['payment_create_error'])) {
            return 'payment_create_failed';
        }

        return match ((int) ($this->attributes['status'] ?? self::STATUS_PENDING)) {
            self::STATUS_CANCELLED => 'gateway_cancelled',
            self::STATUS_EXPIRED => 'gateway_expired',
            default => null,
        };
    }

    public function getFailureMessageAttribute(): ?string
    {
        $extra = is_array($this->extra) ? $this->extra : [];
        if (!empty($extra['payment_create_error'])) {
            return (string) $extra['payment_create_error'];
        }

        return match ($this->failure_reason) {
            'gateway_cancelled' => 'WalletCenter topup payment was cancelled.',
            'gateway_expired' => 'WalletCenter topup payment expired.',
            default => null,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }
}
