<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAudit extends Model
{
    protected $table = 'mobile_app_security_audits';

    protected $guarded = ['id'];

    protected $casts = [
        'meta_json' => 'array',
        'latency_ms' => 'integer',
    ];
}
