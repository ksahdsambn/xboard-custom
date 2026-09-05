<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task017.sqlite',
    'CACHE_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'LOG_CHANNEL' => 'stderr',
    'INSTALLED' => 'true',
    'APP_URL' => 'http://localhost',
] as $key => $value) {
    putenv("$key=$value");
}
putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
require '/audit/vendor/autoload.php';

$tests = [];
function check(string $name, bool $passed, array $details = []): void
{
    global $tests;
    $tests[] = compact('name', 'passed', 'details');
}

function httpRequest(string $method, string $path, array $headers = []): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $key => $value) {
        $server[$key] = $value;
    }
    $request = \Illuminate\Http\Request::create($path, $method, [], [], [], $server);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}

function mobileRoutes(): array
{
    $matched = [];
    foreach (app('router')->getRoutes() as $route) {
        $uri = $route->uri();
        if (!str_starts_with($uri, 'api/mobile/')) {
            continue;
        }
        $matched[] = [
            'uri' => $uri,
            'methods' => $route->methods(),
            'middleware' => $route->gatherMiddleware(),
            'name' => $route->getName(),
        ];
    }
    return $matched;
}

try {
    $app = require '/audit/bootstrap/app.php';
    $console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);

    $migrationExit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    check('official_migrations_run_on_clean_sqlite', $migrationExit === 0);

    $manager = new \App\Services\Plugin\PluginManager();
    $manager->install('mobile_app');
    $record = \App\Models\Plugin::where('code', 'mobile_app')->first();
    check(
        'plugin_installs_disabled_without_exposing_routes',
        $record !== null && !$record->is_enabled && $record->version === '1.0.0'
    );

    $beforeEnable = httpRequest('GET', '/api/mobile/v1/bootstrap');
    check('unenabled_plugin_mobile_routes_unavailable', $beforeEnable->getStatusCode() === 404, [
        'status' => $beforeEnable->getStatusCode(),
    ]);

    $officialLoginBefore = httpRequest('POST', '/api/v1/passport/auth/login');
    check('web_login_available_before_enable', $officialLoginBefore->getStatusCode() !== 404, [
        'status' => $officialLoginBefore->getStatusCode(),
    ]);

    $manager->enable('mobile_app');
    $enabled = \App\Models\Plugin::where('code', 'mobile_app')->value('is_enabled');
    check('plugin_enable_sets_enabled_and_provider', (bool) $enabled && app()->bound('mobile_app.provider'));

    $routes = mobileRoutes();
    $v0 = array_values(array_filter($routes, fn ($item) => str_starts_with($item['uri'], 'api/mobile/v0/')));
    $v1 = array_values(array_filter($routes, fn ($item) => str_starts_with($item['uri'], 'api/mobile/v1/')));
    $other = array_values(array_filter($routes, fn ($item) => !str_starts_with($item['uri'], 'api/mobile/v0/') && !str_starts_with($item['uri'], 'api/mobile/v1/')));
    check('route_prefixes_unique_and_complete', count($v0) === 35 && count($v1) === 35 && $other === [], [
        'v0' => count($v0),
        'v1' => count($v1),
        'other' => count($other),
    ]);

    $public = null;
    $userRoute = null;
    $adminRoute = null;
    $rtdn = null;
    foreach ($v1 as $item) {
        if ($item['uri'] === 'api/mobile/v1/bootstrap') {
            $public = $item;
        }
        if ($item['uri'] === 'api/mobile/v1/account') {
            $userRoute = $item;
        }
        if ($item['uri'] === 'api/mobile/v1/admin/play-products') {
            $adminRoute = $item;
        }
        if ($item['uri'] === 'api/mobile/v1/platform/google/rtdn') {
            $rtdn = $item;
        }
    }
    check(
        'public_routes_do_not_use_user_or_admin',
        $public !== null && !in_array('user', $public['middleware'], true) && !in_array('admin', $public['middleware'], true)
    );
    check(
        'user_routes_require_user_middleware',
        $userRoute !== null && in_array('user', $userRoute['middleware'], true)
    );
    check(
        'admin_routes_require_admin_middleware',
        $adminRoute !== null && in_array('admin', $adminRoute['middleware'], true)
    );
    check(
        'google_callback_uses_platform_middleware',
        $rtdn !== null && in_array('mobile.google.rtdn', $rtdn['middleware'], true) && !in_array('user', $rtdn['middleware'], true)
    );

    $bareCopies = [];
    foreach (app('router')->getRoutes() as $route) {
        $uri = $route->uri();
        if (in_array($uri, ['bootstrap', 'account', 'nodes', 'platform/google/rtdn'], true)) {
            $bareCopies[] = $uri;
        }
    }
    check('no_unprefixed_mobile_route_copies', $bareCopies === [], ['copies' => $bareCopies]);

    $boot = httpRequest('GET', '/api/mobile/v1/bootstrap');
    $boot0 = httpRequest('GET', '/api/mobile/v0/bootstrap');
    $bootJson = json_decode($boot->getContent(), true) ?: [];
    check(
        'enabled_public_routes_return_contract_envelope',
        $boot->getStatusCode() === 501
        && $boot0->getStatusCode() === 501
        && ($bootJson['status'] ?? null) === 'fail'
        && ($bootJson['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
        && isset($bootJson['requestId'])
        && ($bootJson['apiVersion'] ?? null) === 1
    );

    $protected = httpRequest('GET', '/api/mobile/v1/account');
    check('protected_route_without_session_is_forbidden', $protected->getStatusCode() === 403, [
        'status' => $protected->getStatusCode(),
    ]);

    $admin = httpRequest('GET', '/api/mobile/v1/admin/compat');
    check('admin_route_without_admin_is_forbidden', $admin->getStatusCode() === 403, [
        'status' => $admin->getStatusCode(),
    ]);

    $rtdnDenied = httpRequest('POST', '/api/mobile/v1/platform/google/rtdn');
    $rtdnJson = json_decode($rtdnDenied->getContent(), true) ?: [];
    check(
        'google_callback_without_platform_auth_rejected',
        $rtdnDenied->getStatusCode() === 401 && ($rtdnJson['errorCode'] ?? null) === 'PURCHASE_INVALID'
    );
    $rtdnOk = httpRequest('POST', '/api/mobile/v1/platform/google/rtdn', [
        'HTTP_X_MOBILE_RTDN_TEST' => 'fixture-ok',
    ]);
    check('google_callback_with_fixture_reaches_controller', $rtdnOk->getStatusCode() === 501, [
        'status' => $rtdnOk->getStatusCode(),
    ]);

    $login = httpRequest('POST', '/api/v1/passport/auth/login');
    $nodes = httpRequest('GET', '/api/v1/user/server/fetch');
    $orders = httpRequest('GET', '/api/v1/user/order/fetch');
    check('official_web_login_still_available', $login->getStatusCode() !== 404, ['status' => $login->getStatusCode()]);
    check('official_nodes_still_available', $nodes->getStatusCode() !== 404, ['status' => $nodes->getStatusCode()]);
    check('official_orders_still_available', $orders->getStatusCode() !== 404, ['status' => $orders->getStatusCode()]);

    $manager->disable('mobile_app');
    check('plugin_disable_clears_enabled_flag', !\App\Models\Plugin::where('code', 'mobile_app')->value('is_enabled'));

    $freshRaw = trim((string) shell_exec('php /audit/task-017-fresh.php'));
    $fresh = json_decode($freshRaw, true) ?: [];
    check('disabled_fresh_process_hides_mobile_routes', ($fresh['bootstrap'] ?? 0) === 404 && ($fresh['account'] ?? 0) === 404, $fresh);
    check('disabled_fresh_process_keeps_official_login', ($fresh['login'] ?? 0) !== 404, $fresh);

    $manager->enable('mobile_app');
    $reenabled = json_decode(trim((string) shell_exec('php /audit/task-017-fresh.php')), true) ?: [];
    check('plugin_can_be_enabled_again', ($reenabled['bootstrap'] ?? 0) === 501, $reenabled);

    $configPath = '/audit/plugins/MobileApp/config.json';
    $config = json_decode((string) file_get_contents($configPath), true);
    $config['version'] = '1.0.1';
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $updated = $manager->update('mobile_app');
    $newVersion = \App\Models\Plugin::where('code', 'mobile_app')->value('version');
    check(
        'plugin_version_upgrade_runs',
        $updated === true && $newVersion === '1.0.1' && in_array('update:1.0.0:1.0.1', \Plugin\MobileApp\Plugin::$lifecycle, true)
    );

    $afterUpgrade = json_decode(trim((string) shell_exec('php /audit/task-017-fresh.php')), true) ?: [];
    check(
        'upgrade_keeps_mobile_and_official_routes',
        ($afterUpgrade['bootstrap'] ?? 0) === 501 && ($afterUpgrade['login'] ?? 0) !== 404,
        $afterUpgrade
    );

    $officialCoreTouched = [];
    foreach (['app/Providers/RouteServiceProvider.php', 'app/Http/Kernel.php', 'routes/web.php'] as $relative) {
        // Presence only; SHA-256 compared by the runner against the frozen archive.
        if (!is_file('/audit/' . $relative)) {
            $officialCoreTouched[] = $relative;
        }
    }
    check('official_core_files_present_unedited_in_runtime', $officialCoreTouched === [], ['missing' => $officialCoreTouched]);
    check('runtime_image_vendor_preserved', is_dir('/www/vendor'));
    check('plugin_records_remain_queryable', \App\Models\Plugin::query()->where('code', 'mobile_app')->exists());
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-017',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
