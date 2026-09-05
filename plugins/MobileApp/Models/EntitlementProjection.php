<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class EntitlementProjection extends Model
{
    protected $table = 'mobile_app_entitlement_projections';

    protected $guarded = [];

    protected $casts = [
        'expire_at' => 'integer',
        'traffic_bytes' => 'integer',
        'baseline_plan_id' => 'integer',
        'baseline_expired_at' => 'integer',
        'baseline_transfer_enable' => 'integer',
        'baseline_group_id' => 'integer',
    ];
}
