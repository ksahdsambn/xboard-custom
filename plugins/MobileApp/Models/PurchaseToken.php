<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseToken extends Model
{
    protected $table = 'mobile_app_purchase_tokens';

    protected $guarded = [];

    protected $casts = [
        'acknowledged' => 'boolean',
        'next_retry_at' => 'datetime',
    ];
}
