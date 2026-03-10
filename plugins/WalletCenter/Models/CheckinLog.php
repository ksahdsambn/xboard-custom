<?php

namespace Plugin\WalletCenter\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckinLog extends Model
{
    protected $table = 'wallet_center_checkin_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'reward_amount' => 'integer',
        'meta' => 'array',
    ];

    public function getClaimDateAttribute($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
