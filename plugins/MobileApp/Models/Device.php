<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $table = 'mobile_app_devices';

    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'android_api' => 'integer',
        'mobile_api_version' => 'integer',
        'profile_schema_version' => 'integer',
    ];
}
