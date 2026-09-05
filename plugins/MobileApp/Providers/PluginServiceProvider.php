<?php

namespace Plugin\MobileApp\Providers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Plugin\MobileApp\Exceptions\MobileApiException;
use Plugin\MobileApp\Http\Middleware\MobileEnvelopeMiddleware;
use Plugin\MobileApp\Http\Middleware\MobileStartupGate;
use Plugin\MobileApp\Http\Middleware\VerifyGoogleRtdn;
use Plugin\MobileApp\Support\MobileEnvelope;
use Plugin\MobileApp\Support\MobileLocale;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['router']->aliasMiddleware('mobile.envelope', MobileEnvelopeMiddleware::class);
        $this->app['router']->aliasMiddleware('mobile.startup', MobileStartupGate::class);
        $this->app['router']->aliasMiddleware('mobile.google.rtdn', VerifyGoogleRtdn::class);
        $this->app->instance('mobile_app.provider', true);
    }

    public function boot(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->renderable(
            function (MobileApiException $exception, Request $request) {
                if (!str_contains($request->path(), 'api/mobile/')) {
                    return null;
                }
                $locale = MobileLocale::resolve($request);
                return MobileEnvelope::fail(
                    $exception->errorCode,
                    $exception->httpStatus(),
                    $exception->displayMessage($locale)
                );
            }
        );
        $handler->renderable(
            function (AuthenticationException $exception, Request $request) {
                if (!str_contains($request->path(), 'api/mobile/')) {
                    return null;
                }
                return MobileEnvelope::fail('AUTH_SESSION_INVALID', 401);
            }
        );
    }
}
