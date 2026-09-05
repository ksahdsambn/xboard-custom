<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\MobileApp\Exceptions\MobileApiException;

final class MobileRateLimit
{
    public const WINDOW_SECONDS = 60;

    public const LIMITS = [
        'profile' => 30,
        'purchase' => 30,
        'deletion' => 10,
        'rtdn' => 60,
        'diagnostic' => 20,
        'default' => 120,
    ];

    public static function action(Request $request): string
    {
        $path = $request->path();
        if (str_contains($path, '/profiles/')) {
            return 'profile';
        }
        if (str_contains($path, 'play/purchases')) {
            return 'purchase';
        }
        if (str_contains($path, 'account/deletion')) {
            return 'deletion';
        }
        if (str_contains($path, 'platform/google/rtdn')) {
            return 'rtdn';
        }
        if (str_contains($path, '/diagnostics')) {
            return 'diagnostic';
        }
        return 'default';
    }

    public static function cacheKey(string $action, string $ip): string
    {
        return 'mobile_app:rate:' . $action . ':' . $ip;
    }

    public static function hit(Request $request): void
    {
        $action = self::action($request);
        $limit = self::LIMITS[$action] ?? self::LIMITS['default'];
        $ip = (string) ($request->ip() ?: '0.0.0.0');
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
