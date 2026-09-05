<?php

namespace Plugin\MobileApp\Support;

use App\Models\Plugin;

final class PluginStatus
{
    public const CODE = 'mobile_app';

    public static function isEnabled(): bool
    {
        $row = Plugin::query()->where('code', self::CODE)->first();
        return $row !== null && (bool) $row->is_enabled;
    }
}
