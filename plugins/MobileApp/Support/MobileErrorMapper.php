<?php

namespace Plugin\MobileApp\Support;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MobileErrorMapper
{
    public const SESSION_FINGERPRINTS = [
        '未登录或登陆已过期',
        '授权失败，请先登录',
        '账号信息已过期，请重新登录',
        '账号在其他设备登录，请重新登录',
        'Session expired. Please sign in again.',
    ];

    public static function fromCode(string $code, ?int $http = null): array
    {
        if (!MobileErrorCatalog::exists($code)) {
            $code = 'INTERNAL_ERROR';
            $http = 500;
        }
        $resolved = MobileErrorCatalog::resolveHttp($code, $http);
        return [
            'errorCode' => $code,
            'http' => $resolved,
        ];
    }

    public static function fromThrowable(Throwable $exception, ?Request $request = null): array
    {
        if ($exception instanceof MobileApiException) {
            return self::fromCode($exception->errorCode, $exception->http);
        }
        if ($exception instanceof AuthenticationException) {
            return self::fromCode('AUTH_SESSION_INVALID', 401);
        }
        if ($exception instanceof ApiException) {
            $raw = (int) $exception->getCode();
            $http = self::httpFromXboardCode($raw);
            if (self::isSessionFingerprint((string) $exception->getMessage()) || in_array($http, [401], true)) {
                return self::fromCode('AUTH_SESSION_INVALID', $http === 401 ? 401 : 403);
            }
            if ($http === 429 || $raw === 200302) {
                return self::fromCode('AUTH_RATE_LIMITED', 429);
            }
            if ($http === 503 || $raw === 500002) {
                return self::fromCode('SERVICE_MAINTENANCE', 503);
            }
            if (in_array($raw, [401001, 401200, 401201], true)) {
                return self::fromCode('AUTH_SESSION_INVALID', 401);
            }
        }
        return self::fromCode('INTERNAL_ERROR', 500);
    }

    public static function fromOfficialResponse(Request $request, Response $response, array $payload): ?array
    {
        $http = $response->getStatusCode();
        $authenticated = false;
        try {
            $authenticated = (bool) \Illuminate\Support\Facades\Auth::guard('sanctum')->check();
        } catch (Throwable) {
            $authenticated = false;
        }
        if ($http === 403 && !$authenticated) {
            return self::fromCode('AUTH_SESSION_INVALID', 403);
        }
        if ($http === 403 && $authenticated) {
            return self::fromCode('AUTH_FORBIDDEN', 403);
        }
        if ($http === 401) {
            return self::fromCode('AUTH_SESSION_INVALID', 401);
        }
        if ($http === 429) {
            return self::fromCode('AUTH_RATE_LIMITED', 429);
        }
        if ($http === 503) {
            return self::fromCode('SERVICE_MAINTENANCE', 503);
        }
        return null;
    }

    public static function isCompleteEnvelope(array $payload): bool
    {
        foreach (['status', 'message', 'data', 'error', 'errorCode', 'requestId'] as $field) {
            if (!array_key_exists($field, $payload)) {
                return false;
            }
        }
        if (!in_array($payload['status'], ['success', 'fail'], true)) {
            return false;
        }
        if ($payload['status'] === 'fail') {
            return is_string($payload['errorCode']) && $payload['errorCode'] !== '';
        }
        return $payload['errorCode'] === null;
    }

    public static function isSessionFingerprint(string $message): bool
    {
        return in_array(trim($message), self::SESSION_FINGERPRINTS, true);
    }

    private static function httpFromXboardCode(int $code): int
    {
        if ($code >= 100000) {
            return (int) substr((string) $code, 0, 3);
        }
        if ($code >= 100) {
            return $code;
        }
        return 400;
    }
}
