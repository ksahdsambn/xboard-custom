<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class CompatSetting extends Model
{
    protected $table = 'mobile_app_compat_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'maintenance' => 'boolean',
        'region_unavailable' => 'boolean',
        'blocked_regions' => 'array',
        'purchase_enabled' => 'boolean',
        'connect_enabled' => 'boolean',
        'disabled_kernel_versions' => 'array',
        'force_upgrade_enabled' => 'boolean',
        'wallet_enabled' => 'boolean',
        'minimum_android_api' => 'integer',
    ];
}
