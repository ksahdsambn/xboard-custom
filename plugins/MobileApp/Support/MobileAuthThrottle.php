<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\MobileApp\Exceptions\MobileApiException;

final class MobileAuthThrottle
{
    public const WINDOW_SECONDS = 600;
    public const LIMITS = [
        'login' => 30,
        'register' => 10,
        'email-code' => 8,
        'password-reset' => 8,
    ];

    public static function cacheKey(string $action, string $ip): string
    {
        return 'mobile_app:auth_throttle:' . $action . ':' . $ip;
    }

    public static function hit(string $action, Request $request): void
    {
        $ip = (string) ($request->ip() ?: '0.0.0.0');
        $limit = self::LIMITS[$action] ?? 20;
        $key = self::cacheKey($action, $ip);
        if (Cache::add($key, 1, self::WINDOW_SECONDS)) {
            return;
        }
        $count = (int) Cache::increment($key);
        if ($count > $limit) {
            throw new MobileApiException('AUTH_RATE_LIMITED', 429);
        }
    }
}
