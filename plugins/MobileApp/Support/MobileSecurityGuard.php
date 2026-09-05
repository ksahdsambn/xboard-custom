<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;

final class MobileSecurityGuard
{
    public const MAX_REQUEST_BYTES = 65536;
    public const MAX_RTDN_BYTES = 131072;
    public const TIMEOUT_SECONDS = 10;
    public const PAGINATION_REJECT_ABOVE = 1000;
    public const REQUIRE_HTTPS_HEADER = 'X-Mobile-Require-Https';

    public static function enforce(Request $request): void
    {
        self::assertHttps($request);
        self::assertRequestSize($request);
        self::assertPagination($request);
        MobileRateLimit::hit($request);
    }

    public static function httpsRequired(Request $request): bool
    {
        if (function_exists('app') && app()->environment('production')) {
            return true;
        }
        if ($request->headers->get(self::REQUIRE_HTTPS_HEADER) === '1') {
            return true;
        }
        if (function_exists('config') && (bool) config('mobile_app.require_https', false)) {
            return true;
        }
        return false;
    }

    public static function isSecure(Request $request): bool
    {
        if ($request->secure()) {
            return true;
        }
        $forwarded = strtolower((string) $request->headers->get('X-Forwarded-Proto', ''));
        return $forwarded === 'https';
    }

    public static function assertHttps(Request $request): void
    {
        if (self::httpsRequired($request) && !self::isSecure($request)) {
            throw new MobileApiException('HTTPS_REQUIRED', 403);
        }
    }

    public static function assertRequestSize(Request $request): void
    {
        $limit = self::isRtdn($request) ? self::MAX_RTDN_BYTES : self::MAX_REQUEST_BYTES;
        $declared = (int) $request->headers->get('Content-Length', 0);
        $actual = strlen((string) $request->getContent());
        if (max($declared, $actual) > $limit) {
            throw new MobileApiException('REQUEST_TOO_LARGE', 413);
        }
    }

    public static function assertPagination(Request $request): void
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return;
        }
        self::assertPageValue($request->query('page'), 'page');
        self::assertPageValue($request->query('perPage'), 'perPage');
    }

    private static function assertPageValue(mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (is_array($value) || is_object($value) || !is_numeric($value)) {
            throw new MobileApiException('PAGINATION_INVALID', 400);
        }
        $number = (int) $value;
        if ($field === 'page' && ($number < 1 || $number > 1000000)) {
            throw new MobileApiException('PAGINATION_INVALID', 400);
        }
        if ($field === 'perPage' && ($number < 1 || $number > self::PAGINATION_REJECT_ABOVE)) {
            throw new MobileApiException('PAGINATION_INVALID', 400);
        }
    }

    public static function isRtdn(Request $request): bool
    {
        return str_contains($request->path(), 'platform/google/rtdn');
    }
}
