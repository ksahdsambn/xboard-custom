<?php

namespace Plugin\MobileApp\Models;

use Illuminate\Database\Eloquent\Model;

class AccountLink extends Model
{
    protected $table = 'mobile_app_account_links';

    protected $guarded = [];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];
}
