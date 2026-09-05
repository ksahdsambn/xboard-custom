<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class CompatAudit extends Model
{
    protected $table = 'mobile_app_compat_audits';

    protected $guarded = ['id'];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
    ];
}
