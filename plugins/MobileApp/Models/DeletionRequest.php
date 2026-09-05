<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class DeletionRequest extends Model
{
    protected $table = 'mobile_app_deletion_requests';

    protected $guarded = [];

    protected $casts = [
        'play_subscription_warning_ack' => 'boolean',
        'retain_until' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
