<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MobileLogRedactor
{
    public const SENSITIVE_KEYS = [
        'privatekey',
        'realityprivatekey',
        'protocolsettings',
        'subscribeurl',
        'subscriptiontoken',
        'sharelink',
        'clashconfig',
        'singboxconfig',
        'xrayjson',
        'advertisingid',
        'hardwareserial',
        'purchasetoken',
        'sanctumtoken',
        'password',
        'authorization',
        'token',
        'userid',
        'publickey',
        'shortid',
        'uuid',
    ];

    /** @var list<array<string, mixed>> */
    public static array $sink = [];

    public static function redact(mixed $value, string $key = ''): mixed
    {
        if (self::isSensitiveKey($key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $child) {
                $out[$childKey] = self::redact($child, (string) $childKey);
            }
            return $out;
        }
        if (is_string($value) && preg_match('/Bearer\s+\S+/i', $value)) {
            return preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $value);
        }
        return $value;
    }

    public static function error(string $event, array $context, ?Request $request = null): void
    {
        $safe = self::redact($context);
        if (!is_array($safe)) {
            $safe = ['value' => '[redacted]'];
        }
        $safe['event'] = $event;
        if ($request !== null) {
            $safe['requestId'] = MobileRequestId::resolve($request);
            $safe['route'] = optional($request->route())->getName();
        }
        unset($safe['authorization'], $safe['Authorization']);
        self::$sink[] = $safe;
        if (function_exists('app') && app()->bound('log')) {
            Log::info('mobile_app', $safe);
        }
    }

    public static function encodedSink(): string
    {
        return json_encode(self::$sink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
