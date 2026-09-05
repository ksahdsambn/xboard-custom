<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task036.sqlite',
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
    $tests[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
}

try {
    $app = require '/audit/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    \Illuminate\Support\Facades\Queue::fake();

    $overlay = (string) file_get_contents('/audit/xboard-custom-scripts/deploy-overlay.sh');
    $official = (string) file_get_contents('/audit/xboard-custom-scripts/update-official-from-git.sh');
    check(
        'overlay_script_allowlist_includes_mobile_app_only_plugins',
        str_contains($overlay, 'ALLOWED_PLUGINS=(StripePayment BepusdtPayment WalletCenter MobileApp)')
        && str_contains($overlay, 'post-deploy.php')
        && !str_contains($overlay, 'sync_dir "${CUSTOM_ROOT}/app"'),
        ['hasMobileApp' => str_contains($overlay, 'MobileApp')]
    );
    check(
        'official_update_forbids_latest_and_records_three_identities',
        str_contains($official, 'CANDIDATE_IMAGE')
        && str_contains($official, 'Pulling latest is forbidden')
        && str_contains($official, 'Skip compose pull of latest')
        && str_contains($official, 'xboardMaster')
        && str_contains($official, 'xboardCompose')
        && str_contains($official, 'imageDigest')
        && !str_contains($official, '"${COMPOSE_CMD[@]}" pull'),
        ['hasDigest' => str_contains($official, 'sha256')]
    );

    $manager = new \App\Services\Plugin\PluginManager();
    $installError = null;
    try {
        $manager->install('mobile_app');
        $manager->enable('mobile_app');
        app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Plugin\MobileApp\Commands\HealthCommand());
        $healthExit = \Illuminate\Support\Facades\Artisan::call('mobile-app:health');
        $healthOut = \Illuminate\Support\Facades\Artisan::output();
    } catch (\Throwable $exception) {
        $installError = $exception->getMessage();
        $healthExit = 1;
        $healthOut = '';
    }
    $enabled = \App\Models\Plugin::query()->where('code', 'mobile_app')->where('is_enabled', true)->exists();
    check(
        'first_install_enables_plugin_and_passes_health',
        $installError === null && $enabled && $healthExit === 0 && str_contains($healthOut, '"ok":true'),
        ['enabled' => $enabled, 'health' => $healthExit, 'error' => $installError, 'out' => substr($healthOut, 0, 180)]
    );

    $repeatError = null;
    try {
        $existing = \App\Models\Plugin::query()->where('code', 'mobile_app')->first();
        if ($existing) {
            $manager->enable('mobile_app');
        }
        $repeatHealth = \Illuminate\Support\Facades\Artisan::call('mobile-app:health');
    } catch (\Throwable $exception) {
        $repeatError = $exception->getMessage();
        $repeatHealth = 1;
    }
    check('repeat_deploy_is_idempotent', $repeatError === null && $repeatHealth === 0, ['error' => $repeatError, 'health' => $repeatHealth]);

    $configPath = '/audit/plugins/MobileApp/config.json';
    $config = json_decode((string) file_get_contents($configPath), true);
    $config['version'] = '1.0.1';
    file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    $upgradeError = null;
    try {
        $manager->update('mobile_app');
        app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Plugin\MobileApp\Commands\HealthCommand());
        $upgradeHealth = \Illuminate\Support\Facades\Artisan::call('mobile-app:health');
    } catch (\Throwable $exception) {
        $upgradeError = $exception->getMessage();
        $upgradeHealth = 1;
    }
    $version = (string) \App\Models\Plugin::query()->where('code', 'mobile_app')->value('version');
    check(
        'plugin_upgrade_runs_and_stays_healthy',
        $upgradeError === null && $version === '1.0.1' && $upgradeHealth === 0,
        ['version' => $version, 'error' => $upgradeError]
    );

    putenv('MOBILE_APP_FORCE_HEALTH_FAIL=1');
    app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Plugin\MobileApp\Commands\HealthCommand());
    $forced = \Illuminate\Support\Facades\Artisan::call('mobile-app:health');
    putenv('MOBILE_APP_FORCE_HEALTH_FAIL');
    $postDeploy = (string) file_get_contents('/audit/plugins/MobileApp/bin/post-deploy.php');
    check(
        'health_failure_aborts_post_deploy',
        $forced !== 0 && str_contains($postDeploy, 'overlay deploy aborted'),
        ['health' => $forced]
    );

    $simOfficial = '/tmp/official-sim';
    $simCustom = '/tmp/custom-sim';
    @mkdir($simOfficial . '/plugins', 0777, true);
    @mkdir($simOfficial . '/storage/theme', 0777, true);
    @mkdir($simOfficial . '/app', 0777, true);
    file_put_contents($simOfficial . '/app/Kernel.php', "official-core\n");
    foreach (['StripePayment', 'BepusdtPayment', 'WalletCenter', 'MobileApp'] as $plugin) {
        @mkdir($simCustom . '/plugins/' . $plugin, 0777, true);
        file_put_contents($simCustom . '/plugins/' . $plugin . '/marker.txt', $plugin);
        @mkdir($simOfficial . '/plugins/' . $plugin, 0777, true);
        copy($simCustom . '/plugins/' . $plugin . '/marker.txt', $simOfficial . '/plugins/' . $plugin . '/marker.txt');
    }
    @mkdir($simCustom . '/theme/XboardCustom', 0777, true);
    @mkdir($simOfficial . '/storage/theme/XboardCustom', 0777, true);
    file_put_contents($simCustom . '/theme/XboardCustom/marker.txt', 'theme');
    copy($simCustom . '/theme/XboardCustom/marker.txt', $simOfficial . '/storage/theme/XboardCustom/marker.txt');
    $copied = is_file($simOfficial . '/plugins/MobileApp/marker.txt')
        && is_file($simOfficial . '/plugins/StripePayment/marker.txt')
        && is_file($simOfficial . '/storage/theme/XboardCustom/marker.txt');
    $coreUntouched = trim((string) file_get_contents($simOfficial . '/app/Kernel.php')) === 'official-core';
    check(
        'dry_path_sync_does_not_touch_official_core',
        $copied && $coreUntouched && !is_file($simOfficial . '/app/MobileApp.php'),
        ['copied' => $copied, 'core' => $coreUntouched]
    );

    echo json_encode([
        'taskId' => 'TASK-036',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'realProductionDeployClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-036',
        'status' => 'failed',
        'formalAcceptanceClaimed' => false,
        'tests' => array_merge($tests, [[
            'name' => 'runtime_exception',
            'passed' => false,
            'details' => ['type' => $exception::class, 'message' => $exception->getMessage()],
        ]]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
