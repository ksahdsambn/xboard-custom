<?php

namespace Plugin\MobileApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugin\MobileApp\Services\StartupConfigService;
use Plugin\MobileApp\Support\MobileClientHints;
use Symfony\Component\HttpFoundation\Response;

class MobileStartupGate
{
    /** @var list<array{capability:string,path:string,state:string,connect:?string}> */
    public static array $invocations = [];

    public function handle(Request $request, Closure $next, string $capability = 'connect'): Response
    {
        $service = new StartupConfigService();
        $decision = $service->evaluate(MobileClientHints::fromRequest($request));
        self::$invocations[] = [
            'capability' => $capability,
            'path' => $request->path(),
            'state' => (string) ($decision['startupState'] ?? ''),
            'connect' => $decision['connectErrorCode'] ?? null,
        ];
        $service->assertCapability($capability, $decision);
        return $next($request);
    }
}
