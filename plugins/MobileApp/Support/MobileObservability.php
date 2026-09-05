<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\Request;
use Plugin\MobileApp\Services\SecurityAuditService;
use Symfony\Component\HttpFoundation\Response;

final class MobileObservability
{
    public const ATTR_STARTED = 'mobile_observe_started';

    /** @var list<array<string, mixed>> */
    public static array $metrics = [];

    public static function reset(): void
    {
        self::$metrics = [];
    }

    public static function start(Request $request): void
    {
        $request->attributes->set(self::ATTR_STARTED, microtime(true));
    }

    public static function record(Request $request, Response $response): void
    {
        $started = (float) $request->attributes->get(self::ATTR_STARTED, microtime(true));
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $payload = json_decode((string) $response->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $errorCode = isset($payload['errorCode']) && is_string($payload['errorCode']) ? $payload['errorCode'] : null;
        $operation = self::operation($request);
        $outcome = ($payload['status'] ?? '') === 'success' || $response->getStatusCode() < 400 ? 'ok' : 'rejected';
        $metric = MobileLogRedactor::redact([
            'requestId' => MobileRequestId::resolve($request),
            'route' => optional($request->route())->getName(),
            'operation' => $operation,
            'outcome' => $outcome,
            'errorCode' => $errorCode,
            'latencyMs' => $latencyMs,
            'http' => $response->getStatusCode(),
        ]);
        if (!is_array($metric)) {
            $metric = ['value' => '[redacted]'];
        }
        self::$metrics[] = $metric;
        MobileLogRedactor::error('mobile_metric', $metric, $request);
        if (in_array($operation, SecurityAuditService::OPERATIONS, true)) {
            SecurityAuditService::record($request, $operation, $outcome, $errorCode, $latencyMs, $metric);
        }
    }

    public static function operation(Request $request): string
    {
        $path = $request->path();
        if (str_contains($path, '/auth/')) {
            return 'auth';
        }
        if (str_contains($path, '/profiles/')) {
            return 'profile';
        }
        if (str_contains($path, 'play/purchases')) {
            return 'purchase';
        }
        if (str_contains($path, 'platform/google/rtdn')) {
            return 'rtdn';
        }
        if (str_contains($path, 'account/deletion')) {
            return 'deletion';
        }
        if (str_contains($path, '/diagnostics')) {
            return 'diagnostic';
        }
        return 'other';
    }
}
