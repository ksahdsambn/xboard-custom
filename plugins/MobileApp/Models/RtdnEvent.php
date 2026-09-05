<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class RtdnEvent extends Model
{
    protected $table = 'mobile_app_rtdn_events';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];
}
