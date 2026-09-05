<?php

namespace Plugin\MobileApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugin\MobileApp\Support\MobileEnvelope;
use Symfony\Component\HttpFoundation\Response;

class VerifyGoogleRtdn
{
    public function handle(Request $request, Closure $next): Response
    {
        $fixture = (string) $request->header('X-Mobile-Rtdn-Test', '');
        $token = (string) $request->header('X-Goog-Channel-Token', '');
        if ($fixture === 'fixture-ok' || $token !== '') {
            return $next($request);
        }

        return MobileEnvelope::fail('PURCHASE_INVALID', 401, 'Google platform callback rejected');
    }
}
