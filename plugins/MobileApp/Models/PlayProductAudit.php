<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class PlayProductAudit extends Model
{
    protected $table = 'mobile_app_play_product_audits';

    protected $guarded = ['id'];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'play_product_id' => 'integer',
        'actor_user_id' => 'integer',
    ];
}
