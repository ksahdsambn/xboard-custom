<?php

namespace Plugin\MobileApp\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Support\MobileClientDecision;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\MobileErrorCatalog;
use Plugin\MobileApp\Support\MobileErrorMapper;
use Plugin\MobileApp\Support\MobileLocale;
use Plugin\MobileApp\Support\MobileLogRedactor;
use Plugin\MobileApp\Support\MobileRequestId;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MobileEnvelopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = MobileRequestId::bind($request);
        $locale = MobileLocale::resolve($request);
        $request->attributes->set('mobile_locale', $locale);

        try {
            if (self::allowsFixture() && $request->headers->has('X-Mobile-Error-Fixture')) {
                $code = (string) $request->headers->get('X-Mobile-Error-Fixture');
                $httpHeader = $request->headers->get('X-Mobile-Error-Http');
                $http = is_numeric($httpHeader) ? (int) $httpHeader : null;
                throw new MobileApiException($code, $http);
            }
            $response = $next($request);
            return $this->finalize($request, $response, $requestId, $locale);
        } catch (MobileApiException $exception) {
            return $this->respond($exception->errorCode, $exception->httpStatus(), $locale, $requestId, $exception->displayMessage($locale));
        } catch (AuthenticationException $exception) {
            MobileLogRedactor::error('auth_exception', ['type' => $exception::class], $request);
            return $this->respond('AUTH_SESSION_INVALID', 401, $locale, $requestId);
        } catch (ApiException $exception) {
            $mapped = MobileErrorMapper::fromThrowable($exception, $request);
            MobileLogRedactor::error('mapped_api_exception', [
                'type' => $exception::class,
                'errorCode' => $mapped['errorCode'],
            ], $request);
            return $this->respond($mapped['errorCode'], $mapped['http'], $locale, $requestId);
        } catch (Throwable $exception) {
            MobileLogRedactor::error('unhandled', ['type' => $exception::class], $request);
            return $this->respond('INTERNAL_ERROR', 500, $locale, $requestId);
        }
    }

    public static function allowsFixture(?string $env = null): bool
    {
        $env = $env ?? (function_exists('app') ? app()->environment() : 'production');
        return $env === 'testing';
    }

    public static function clientDecision(int $http, array $body): string
    {
        return MobileClientDecision::decide($http, $body);
    }

    private function finalize(Request $request, Response $response, string $requestId, string $locale): Response
    {
        $response->headers->set(MobileRequestId::HEADER, $requestId);
        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload)) {
            return $response;
        }
        if (MobileErrorMapper::isCompleteEnvelope($payload)) {
            if (($payload['requestId'] ?? null) !== $requestId) {
                $payload['requestId'] = $requestId;
                $response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            return $response;
        }
        $mapped = MobileErrorMapper::fromOfficialResponse($request, $response, $payload);
        if ($mapped === null) {
            return $response;
        }
        return $this->respond($mapped['errorCode'], $mapped['http'], $locale, $requestId);
    }

    private function respond(string $errorCode, int $http, string $locale, string $requestId, ?string $message = null): Response
    {
        $http = MobileErrorCatalog::resolveHttp($errorCode, $http);
        $response = MobileEnvelope::fail($errorCode, $http, $message);
        $response->headers->set(MobileRequestId::HEADER, $requestId);
        $payload = json_decode((string) $response->getContent(), true) ?: [];
        $payload['requestId'] = $requestId;
        $response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }
}
