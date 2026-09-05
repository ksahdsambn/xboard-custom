<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MobileRequestId
{
    public const HEADER = 'X-Request-Id';
    public const ATTR = 'mobile_request_id';

    public static function bind(Request $request): string
    {
        $id = self::resolve($request);
        $request->attributes->set(self::ATTR, $id);
        $request->headers->set(self::HEADER, $id);
        return $id;
    }

    public static function resolve(?Request $request = null): string
    {
        $request ??= request();
        $existing = (string) $request->attributes->get(self::ATTR, '');
        if (self::isUuid($existing)) {
            return $existing;
        }
        $header = (string) $request->headers->get(self::HEADER, '');
        if (self::isUuid($header)) {
            return $header;
        }
        return (string) Str::uuid();
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $value
        );
    }
}
