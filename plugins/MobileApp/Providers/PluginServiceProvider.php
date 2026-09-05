<?php

namespace Plugin\MobileApp\Providers;

use Illuminate\Support\ServiceProvider;
use Plugin\MobileApp\Http\Middleware\VerifyGoogleRtdn;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['router']->aliasMiddleware('mobile.google.rtdn', VerifyGoogleRtdn::class);
        $this->app->instance('mobile_app.provider', true);
    }
}
