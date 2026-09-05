<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;

final class MobileLocale
{
    public static function resolve(?Request $request = null): string
    {
        $request ??= request();
        $explicit = strtolower((string) $request->headers->get('X-Locale', ''));
        if (str_starts_with($explicit, 'en')) {
            return 'en';
        }
        if (str_starts_with($explicit, 'zh')) {
            return 'zh';
        }
        $accept = strtolower((string) $request->headers->get('Accept-Language', ''));
        if (str_starts_with($accept, 'en')) {
            return 'en';
        }
        return 'zh';
    }
}
