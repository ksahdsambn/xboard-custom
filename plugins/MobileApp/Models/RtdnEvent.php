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
        'event_time_millis' => 'integer',
        'claimed_notification_type' => 'integer',
        'apply_count' => 'integer',
        'retry_count' => 'integer',
    ];
}
