<?php

namespace Plugin\MobileApp\Support;

use Illuminate\Http\JsonResponse;

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
        return self::json('success', 'ok', $data, null, $http);
    }

    public static function paginate(array $items, int $page, int $perPage, int $total): JsonResponse
    {
        return self::success(MobilePaginator::payload($items, $page, $perPage, $total));
    }

    public static function fail(string $errorCode, int $http, ?string $message = null): JsonResponse
    {
        $locale = 'zh';
        try {
            $locale = (string) (request()->attributes->get('mobile_locale') ?: MobileLocale::resolve());
        } catch (\Throwable) {
            $locale = 'zh';
        }
        $message ??= MobileErrorCatalog::message($errorCode, $locale);
        return self::json('fail', $message, null, $errorCode, $http);
    }

    public static function requestId(): string
    {
        try {
            return MobileRequestId::resolve();
        } catch (\Throwable) {
            return (string) \Illuminate\Support\Str::uuid();
        }
    }

    private static function json(string $status, string $message, mixed $data, ?string $errorCode, int $http): JsonResponse
    {
        $apiVersion = self::apiVersionFromRequest();
        $requestId = self::requestId();
        $payload = [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'error' => null,
            'errorCode' => $errorCode,
            'requestId' => $requestId,
        ];
        if ($apiVersion === 1) {
            $payload['apiVersion'] = 1;
        }
        $response = response()->json($payload, $http);
        $response->headers->set(MobileRequestId::HEADER, $requestId);
        return $response;
    }
}
