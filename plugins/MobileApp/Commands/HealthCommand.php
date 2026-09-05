<?php

namespace Plugin\MobileApp\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Plugin\MobileApp\Support\PluginStatus;

class HealthCommand extends Command
{
    protected $signature = 'mobile-app:health';

    protected $description = 'Verify MobileApp enablement, versioned routes and plugin tables without exposing secrets.';

    public function handle(): int
    {
        $testing = function_exists('app') && app()->environment('testing');
        if ($testing && getenv('MOBILE_APP_FORCE_HEALTH_FAIL') === '1') {
            $this->line(json_encode([
                'ok' => false,
                'reason' => 'forced_failure',
                'formalAcceptanceClaimed' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $routes = collect(app('router')->getRoutes())->map(static fn ($route): string => (string) $route->uri());
        $hasV0 = $routes->contains(static fn (string $uri): bool => str_contains($uri, 'api/mobile/v0/bootstrap'));
        $hasV1 = $routes->contains(static fn (string $uri): bool => str_contains($uri, 'api/mobile/v1/bootstrap'));
        $payload = [
            'ok' => PluginStatus::isEnabled() && $hasV0 && $hasV1 && Schema::hasTable('mobile_app_devices'),
            'enabled' => PluginStatus::isEnabled(),
            'routes' => ['v0' => $hasV0, 'v1' => $hasV1],
            'tables' => ['mobile_app_devices' => Schema::hasTable('mobile_app_devices')],
            'formalAcceptanceClaimed' => false,
        ];
        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
