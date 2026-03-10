<?php

namespace Plugin\WalletCenter\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoRenewSetting extends Model
{
    protected $table = 'wallet_center_auto_renew_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'snapshot' => 'array',
        'next_scan_at' => 'datetime',
        'last_result_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
