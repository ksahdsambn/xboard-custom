<?php

namespace Plugin\MobileApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugin\MobileApp\Support\MobileEnvelope;
use Symfony\Component\HttpFoundation\Response;

class VerifyGoogleRtdn
{
    public const FIXTURE_CHANNEL_TOKEN = 'rtdn-sandbox-channel';

    public const FIXTURE_HEADER = 'fixture-ok';

    public static function signature(string $rawBody): string
    {
        return hash('sha256', self::FIXTURE_CHANNEL_TOKEN . '.' . $rawBody);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $fixture = (string) $request->header('X-Mobile-Rtdn-Test', '');
        $token = (string) $request->header('X-Goog-Channel-Token', '');
        $signature = (string) $request->header('X-Mobile-Rtdn-Signature', '');
        if ($fixture !== self::FIXTURE_HEADER || $token !== self::FIXTURE_CHANNEL_TOKEN) {
            return MobileEnvelope::fail('PURCHASE_INVALID', 401, 'Google platform callback rejected');
        }
        $raw = (string) $request->getContent();
        $expected = self::signature($raw);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            return MobileEnvelope::fail('PURCHASE_INVALID', 401, 'Google platform callback rejected');
        }

        return $next($request);
    }
}
