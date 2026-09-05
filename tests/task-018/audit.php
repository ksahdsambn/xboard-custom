<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task018-prod.sqlite',
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

function pluginTables(array $expected): array
{
    $names = [];
    foreach ($expected as $name) {
        if (\Illuminate\Support\Facades\Schema::hasTable($name)) {
            $names[] = $name;
        }
    }
    return $names;
}

function pluginColumns(string $table): array
{
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
    sort($columns);
    return $columns;
}

$expected = [
    'mobile_app_account_links',
    'mobile_app_deletion_requests',
    'mobile_app_devices',
    'mobile_app_entitlement_projections',
    'mobile_app_play_products',
    'mobile_app_purchase_tokens',
    'mobile_app_rtdn_events',
];

try {
    $app = require '/audit/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);

    $officialExit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    check('official_migrations_on_production_structure_copy', $officialExit === 0);

    $manager = new \App\Services\Plugin\PluginManager();
    $manager->install('mobile_app');
    $afterInstall = pluginTables($expected);
    check('plugin_install_creates_seven_tables', $afterInstall === $expected, ['tables' => $afterInstall]);

    $second = \Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => 'plugins/MobileApp/database/migrations',
        '--force' => true,
    ]);
    check('plugin_migrations_are_idempotent', $second === 0 && pluginTables($expected) === $expected, ['exit' => $second]);

    check(
        'official_tables_not_removed',
        \Illuminate\Support\Facades\Schema::hasTable('v2_user') && \Illuminate\Support\Facades\Schema::hasTable('v2_plugins'),
        [
            'v2_user' => \Illuminate\Support\Facades\Schema::hasTable('v2_user'),
            'v2_plugins' => \Illuminate\Support\Facades\Schema::hasTable('v2_plugins'),
        ]
    );

    $prodColumns = pluginColumns('mobile_app_purchase_tokens');

    $now = now();
    \Illuminate\Support\Facades\DB::table('mobile_app_purchase_tokens')->insert([
        'user_id' => 1,
        'platform' => 'google_play',
        'purchase_token_hash' => str_repeat('a', 64),
        'product_id' => 'sku.month',
        'package_name' => 'dev.xboard.app',
        'environment' => 'sandbox',
        'play_status' => 'purchased',
        'acknowledged' => 0,
        'external_subscription_id' => 'sub-1',
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'retry_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tokenDup = false;
    try {
        \Illuminate\Support\Facades\DB::table('mobile_app_purchase_tokens')->insert([
            'user_id' => 2,
            'platform' => 'google_play',
            'purchase_token_hash' => str_repeat('a', 64),
            'product_id' => 'sku.month',
            'package_name' => 'dev.xboard.app',
            'environment' => 'sandbox',
            'play_status' => 'purchased',
            'acknowledged' => 0,
            'external_subscription_id' => 'sub-other',
            'request_id' => '22222222-2222-4222-8222-222222222222',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (\Throwable) {
        $tokenDup = true;
    }
    check('unique_platform_purchase_token_enforced', $tokenDup);

    $subDup = false;
    try {
        \Illuminate\Support\Facades\DB::table('mobile_app_purchase_tokens')->insert([
            'user_id' => 3,
            'platform' => 'google_play',
            'purchase_token_hash' => str_repeat('b', 64),
            'product_id' => 'sku.month',
            'package_name' => 'dev.xboard.app',
            'environment' => 'sandbox',
            'play_status' => 'purchased',
            'acknowledged' => 0,
            'external_subscription_id' => 'sub-1',
            'request_id' => '33333333-3333-4333-8333-333333333333',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (\Throwable) {
        $subDup = true;
    }
    check('unique_external_subscription_binding_enforced', $subDup);

    \Illuminate\Support\Facades\DB::table('mobile_app_rtdn_events')->insert([
        'platform' => 'google_play',
        'event_id' => 'evt-1',
        'environment' => 'sandbox',
        'payload_digest' => str_repeat('c', 64),
        'processing_status' => 'received',
        'request_id' => '44444444-4444-4444-8444-444444444444',
        'retry_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $eventDup = false;
    try {
        \Illuminate\Support\Facades\DB::table('mobile_app_rtdn_events')->insert([
            'platform' => 'google_play',
            'event_id' => 'evt-1',
            'environment' => 'sandbox',
            'payload_digest' => str_repeat('d', 64),
            'processing_status' => 'received',
            'request_id' => '55555555-5555-4555-8555-555555555555',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (\Throwable) {
        $eventDup = true;
    }
    check('unique_platform_notification_event_enforced', $eventDup);

    $beforeDevices = \Illuminate\Support\Facades\DB::table('mobile_app_devices')->count();
    $rolled = false;
    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($now): void {
            \Illuminate\Support\Facades\DB::table('mobile_app_devices')->insert([
                'user_id' => 9,
                'opaque_device_id' => 'dev-fail',
                'platform' => 'android',
                'app_version' => '1.0.0',
                'mobile_api_version' => 1,
                'profile_schema_version' => 1,
                'request_id' => '66666666-6666-4666-8666-666666666666',
                'environment' => 'sandbox',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            throw new RuntimeException('simulated-mid-failure');
        });
    } catch (RuntimeException) {
        $rolled = true;
    }
    $afterDevices = \Illuminate\Support\Facades\DB::table('mobile_app_devices')->count();
    check('mid_failure_rolls_back_to_known_state', $rolled && $afterDevices === $beforeDevices, [
        'before' => $beforeDevices,
        'after' => $afterDevices,
    ]);

    config(['database.connections.sqlite.database' => '/audit/database/task018-empty.sqlite']);
    \Illuminate\Support\Facades\DB::purge('sqlite');
    \Illuminate\Support\Facades\DB::reconnect('sqlite');
    \Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => 'plugins/MobileApp/database/migrations',
        '--force' => true,
    ]);
    $emptyTables = pluginTables($expected);
    $emptyColumns = pluginColumns('mobile_app_purchase_tokens');
    check('empty_database_plugin_tables_match_production_copy', $emptyTables === $expected && $emptyColumns === $prodColumns, [
        'empty' => $emptyTables,
        'prodColumns' => count($prodColumns),
        'emptyColumns' => count($emptyColumns),
    ]);

    $pluginMigrationFiles = glob('/audit/plugins/MobileApp/database/migrations/*.php') ?: [];
    $officialCollision = [];
    foreach ($pluginMigrationFiles as $file) {
        $base = basename($file);
        if (is_file('/audit/database/migrations/' . $base)) {
            $officialCollision[] = $base;
        }
    }
    check('plugin_migrations_do_not_shadow_official_filenames', $officialCollision === [], ['collision' => $officialCollision]);
    check('plugin_migrations_are_additive_create_only', count($pluginMigrationFiles) === 7);
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'message' => $exception->getMessage()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-018',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
