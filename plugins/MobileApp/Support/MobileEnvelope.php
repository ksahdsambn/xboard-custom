<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class MobileEnvelope
{
    public static function apiVersionFromRequest(): int
    {
        $path = (string) request()->path();
        if (str_contains($path, '/api/mobile/v0/') || str_starts_with($path, 'api/mobile/v0/')) {
            return 0;
        }
        return 1;
    }

    public static function success(mixed $data, int $http = 200): JsonResponse
    {
        $apiVersion = self::apiVersionFromRequest();
        $payload = [
            'status' => 'success',
            'message' => 'ok',
            'data' => $data,
            'error' => null,
            'errorCode' => null,
            'requestId' => self::requestId(),
        ];
        if ($apiVersion === 1) {
            $payload['apiVersion'] = 1;
        }
        return response()->json($payload, $http);
    }

    public static function fail(string $errorCode, int $http, string $message): JsonResponse
    {
        $apiVersion = self::apiVersionFromRequest();
        $payload = [
            'status' => 'fail',
            'message' => $message,
            'data' => null,
            'error' => null,
            'errorCode' => $errorCode,
            'requestId' => self::requestId(),
        ];
        if ($apiVersion === 1) {
            $payload['apiVersion'] = 1;
        }
        return response()->json($payload, $http);
    }

    public static function requestId(): string
    {
        $header = request()->header('X-Request-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        return (string) Str::uuid();
    }
}
