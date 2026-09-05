<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class PlayProduct extends Model
{
    protected $table = 'mobile_app_play_products';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
