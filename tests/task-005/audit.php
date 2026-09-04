<?php
declare(strict_types=1);

// This file is copied into a disposable upstream checkout, never deployed.
chdir('/audit');
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task005.sqlite', 'CACHE_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
    'INSTALLED' => 'true', 'APP_URL' => 'http://localhost'] as $key => $value) {
    putenv("$key=$value");
}
putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
require '/audit/vendor/autoload.php';

$tests = [];
$snapshot = [];
function check(string $name, bool $passed, array $details = []): void
{
    global $tests;
    $tests[] = compact('name', 'passed', 'details');
}
function httpRequest(string $path, ?string $authorization = null): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $headers = ['HTTP_ACCEPT' => 'application/json'];
    if ($authorization !== null) $headers['HTTP_AUTHORIZATION'] = $authorization;
    $request = \Illuminate\Http\Request::create($path, 'GET', [], [], [], $headers);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}

try {
    $app = require '/audit/bootstrap/app.php';
    $console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
    // Setting explicitly selects the store named redis. Use a real in-memory cache
    // adapter for this single-process contract audit; this does not test Redis races.
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);
    $migrationExit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    check('official_migrations_run_on_clean_sqlite', $migrationExit === 0);

    // Compare every installed production dependency with the frozen lock, not the image vendor tree.
    $lock = json_decode(file_get_contents('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $dependencyErrors = [];
    foreach ($lock['packages'] as $package) {
        if (!\Composer\InstalledVersions::isInstalled($package['name']) ||
            \Composer\InstalledVersions::getPrettyVersion($package['name']) !== $package['version'] ||
            \Composer\InstalledVersions::getReference($package['name']) !== ($package['source']['reference'] ?? $package['dist']['reference'] ?? null)) {
            $dependencyErrors[] = $package['name'];
        }
    }
    check('dependencies_match_frozen_lock', $dependencyErrors === [], ['mismatches' => $dependencyErrors]);
    $snapshot['runtime'] = ['php' => PHP_VERSION, 'laravel' => app()->version(),
        'sanctum' => \Composer\InstalledVersions::getPrettyVersion('laravel/sanctum'),
        'composerLockSha256' => hash_file('sha256', 'composer.lock'),
        'testAdapters' => ['database' => 'sqlite', 'defaultCache' => 'array', 'namedRedisCache' => 'array'],
        'limitations' => ['no Redis/MySQL concurrency test', 'no external captcha provider validation', 'HTTP kernel request, not socket transport']];

    $manager = new \App\Services\Plugin\PluginManager();
    $manager->install('task005_probe');
    $record = \App\Models\Plugin::where('code', 'task005_probe')->firstOrFail();
    check('plugin_install_migrates_but_is_disabled', !$record->is_enabled && \Illuminate\Support\Facades\Schema::hasTable('task005_probe') && in_array('install', \Plugin\Task005Probe\Plugin::$calls, true));
    $manager->enable('task005_probe');
    check('plugin_enable_loads_provider_config_boot', app('task005.provider') === true && app('task005.config')['probe_enabled'] === true && in_array('boot', \Plugin\Task005Probe\Plugin::$calls, true));
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $public = $routes->getByName('task005.public');
    $unprefixed = $routes->getByName('task005.unprefixed');
    check('plugin_api_routes_have_no_implicit_version_prefix', $public->uri() === 'api/mobile/v1/task005-probe/public' && $unprefixed->uri() === 'task005-unprefixed');
    check('plugin_routes_only_inherit_api_group', $public->gatherMiddleware() === ['api']);
    $snapshot['routes'] = ['unprefixed' => $unprefixed->uri(), 'explicitPrefix' => $public->uri(), 'middleware' => $public->gatherMiddleware()];
    $freshManager = new \App\Services\Plugin\PluginManager();
    $freshManager->initializeEnabledPlugins();
    $boots = count(\Plugin\Task005Probe\Plugin::$calls);
    $freshManager->initializeEnabledPlugins();
    check('plugin_initialization_is_idempotent_per_manager', count(\Plugin\Task005Probe\Plugin::$calls) === $boots);
    check('plugin_commands_registered', \Illuminate\Support\Facades\Artisan::call('task005:probe') === 0 && str_contains(\Illuminate\Support\Facades\Artisan::output(), 'probe-ok'));
    $schedule = new \Illuminate\Console\Scheduling\Schedule();
    $freshManager->registerPluginSchedules($schedule);
    check('plugin_schedule_registered', count($schedule->events()) === 1 && $schedule->events()[0]->expression === '* * * * *');

    $invalid = httpRequest('/api/mobile/v1/task005-probe/protected', 'Bearer task005-invalid');
    $body = json_decode($invalid->getContent(), true);
    check('invalid_sanctum_http_403_and_fail_envelope', $invalid->getStatusCode() === 403 && ($body['status'] ?? null) === 'fail' && array_keys($body) === ['status', 'message', 'data', 'error']);
    $snapshot['invalidSanctum'] = ['httpStatus' => $invalid->getStatusCode(), 'body' => $body];
    $publicResponse = httpRequest('/api/mobile/v1/task005-probe/public');
    check('public_probe_http_200', $publicResponse->getStatusCode() === 200 && json_decode($publicResponse->getContent(), true) === ['probe' => true]);

    $userService = new \App\Services\UserService();
    $password = bin2hex(random_bytes(24));
    $user = $userService->createUser(['email' => 'task005@example.invalid', 'password' => $password, 'group_id' => 9101]);
    $user->transfer_enable = 1000; $user->expired_at = time() + 3600; $user->save();
    $auth = (new \App\Services\AuthService($user))->generateAuthData();
    check('sanctum_bearer_is_distinct_from_subscription_token', str_starts_with($auth['auth_data'], 'Bearer ') && substr($auth['auth_data'], 7) !== $auth['token']);
    check('sanctum_bearer_authorizes_protected_route', httpRequest('/api/mobile/v1/task005-probe/protected', $auth['auth_data'])->getStatusCode() === 200);
    check('subscription_token_is_not_session', httpRequest('/api/mobile/v1/task005-probe/protected', 'Bearer '.$auth['token'])->getStatusCode() === 403);
    $user->tokens()->update(['expires_at' => now()->subMinute()]);
    check('expired_session_rejected_by_sanctum', httpRequest('/api/mobile/v1/task005-probe/protected', $auth['auth_data'])->getStatusCode() === 403);
    check('manual_bearer_lookup_does_not_validate_expiry_risk', \App\Services\AuthService::findUserByBearerToken($auth['auth_data']) !== null);
    $login = new \App\Services\Auth\LoginService();
    check('login_valid_password_returns_user', $login->login($user->email, $password)[0] === true);
    admin_setting(['password_limit_enable' => 1, 'password_limit_count' => 2]);
    $first = $login->login($user->email, 'wrong');
    $second = $login->login($user->email, 'wrong');
    $limited = $login->login($user->email, $password);
    check('login_bad_password_400_then_limit_429', $first[1][0] === 400 && $second[1][0] === 400 && $limited[1][0] === 429);
    \Illuminate\Support\Facades\Cache::forget(\App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $user->email));
    $register = new \App\Services\Auth\RegisterService();
    $registration = \Illuminate\Http\Request::create('/', 'POST', ['email' => 'new@example.invalid', 'password' => $password]);
    admin_setting(['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['allowed.invalid']]);
    check('registration_email_whitelist_rejects', $register->validateRegister($registration)[1][0] === 400);
    admin_setting(['email_whitelist_enable' => 0, 'invite_force' => 1]);
    check('registration_requires_invite_422', $register->validateRegister($registration)[1][0] === 422);
    $invite = new \App\Models\InviteCode();
    $invite->code = 'task005fixture'; $invite->user_id = $user->id; $invite->status = 0; $invite->save();
    $inviter = $register->handleInviteCode('task005fixture');
    check('invitation_consumed_and_returns_inviter', $inviter === $user->id && $invite->fresh()->status == \App\Models\InviteCode::STATUS_USED);
    admin_setting(['invite_force' => 0, 'email_verify' => 1]);
    $missing = $register->validateRegister($registration);
    $registration->merge(['email_code' => '654321']);
    $wrong = $register->validateRegister($registration);
    \Illuminate\Support\Facades\Cache::put(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.invalid'), '654321', 300);
    $registered = $register->register($registration);
    check('email_code_missing_wrong_valid_registration', $missing[1][0] === 422 && $wrong[1][0] === 400 && $registered[0] === true && $registered[1]->exists && !\Illuminate\Support\Facades\Cache::has(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.invalid')));
    admin_setting(['email_verify' => 0, 'captcha_enable' => 1]);
    foreach (['turnstile', 'recaptcha-v3', 'recaptcha'] as $mode) {
        admin_setting(['captcha_type' => $mode]);
        check('captcha_missing_token_rejected_'.$mode, (new \App\Services\CaptchaService())->verify(\Illuminate\Http\Request::create('/'))[1][0] === 400);
    }
    admin_setting(['captcha_enable' => 0]);
    $resetWrong = $login->resetPassword($user->email, '000000', $password);
    $newPassword = bin2hex(random_bytes(24));
    \Illuminate\Support\Facades\Cache::put(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $user->email), '123456', 300);
    $resetValid = $login->resetPassword($user->email, '123456', $newPassword);
    check('password_reset_verifies_code_hash_and_consumes_code', $resetWrong[1][0] === 400 && $resetValid[0] === true && password_verify($newPassword, $user->fresh()->password) && !\Illuminate\Support\Facades\Cache::has(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
    check('password_reset_service_does_not_revoke_sessions_risk', $user->tokens()->count() === 1);
    $user->u = 1000; $user->d = 1;
    check('user_service_availability_omits_consumed_traffic_risk', $userService->isAvailable($user) === true);
    $user->u = 0; $user->d = 0; $user->save();

    $plan = \App\Models\Plan::create(['name' => 'visible-plan', 'group_id' => 9101, 'transfer_enable' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'prices' => ['monthly' => 100], 'sort' => 1, 'capacity_limit' => null]);
    $hiddenPlan = \App\Models\Plan::create(['name' => 'hidden-plan', 'group_id' => 9101, 'transfer_enable' => 1, 'show' => false, 'sell' => true, 'renew' => true]);
    $planService = new \App\Services\PlanService($plan);
    check('plan_list_filters_hidden_but_single_lookup_does_not_risk', $planService->getAvailablePlans()->pluck('id')->all() === [$plan->id] && $planService->getAvailablePlan($hiddenPlan->id) !== null);
    check('plan_period_normalizes_legacy_and_price_is_integer', \App\Services\PlanService::getPeriodKey('month_price') === 'monthly' && $plan->prices['monthly'] === 100);
    $plan->capacity_limit = 0; $plan->save();
    check('plan_capacity_excludes_sold_out', $planService->getAvailablePlans()->isEmpty() && !$planService->isPlanAvailableForUser($plan, $user));
    $periodRisk = false;
    try { $planService->getAvailablePeriods($plan); } catch (\TypeError $error) { $periodRisk = true; }
    check('plan_available_periods_array_key_type_error_risk', $periodRisk);

    $base = ['type' => 'vless', 'host' => 'node.example.invalid', 'port' => '24000-24010', 'server_port' => 24443, 'group_ids' => ['9101'],
        'show' => true, 'rate' => 1, 'tags' => ['development-audit'], 'u' => 0, 'd' => 0, 'transfer_enable' => 0,
        'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
            'reality_settings' => ['server_name' => 'target.example.invalid', 'public_key' => 'fixture-public-only', 'short_id' => 'ab12']]];
    $allowed = \App\Models\Server::create($base + ['name' => 'allowed', 'sort' => 2]);
    $fixed = \App\Models\Server::create(array_replace($base, ['name' => 'fixed', 'sort' => 1, 'port' => '24443']));
    \App\Models\Server::create(array_replace($base, ['name' => 'wrong-group', 'group_ids' => ['9102']]));
    \App\Models\Server::create(array_replace($base, ['name' => 'hidden', 'show' => false]));
    \App\Models\Server::create(array_replace($base, ['name' => 'exhausted', 'transfer_enable' => 100, 'u' => 50, 'd' => 50]));
    $servers = \App\Services\ServerService::getAvailableServers($user);
    check('server_authorization_preserves_group_show_quota_sort', array_column($servers, 'id') === [$fixed->id, $allowed->id]);
    check('server_dynamic_port_and_user_credential_preserved', is_int($servers[1]['port']) && $servers[1]['port'] >= 24000 && $servers[1]['port'] <= 24010 && $servers[1]['ports'] === '24000-24010' && $servers[1]['password'] === $user->uuid);
    $summaries = array_map(fn ($server) => (new \App\Http\Resources\NodeResource($server))->resolve(), $servers);
    check('node_summary_has_same_authorized_set_but_no_credentials', array_column($summaries, 'id') === array_column($servers, 'id') && !array_key_exists('password', $summaries[1]) && !array_key_exists('protocol_settings', $summaries[1]) && !array_key_exists('host', $summaries[1]));
    $snapshot['nodes'] = ['seededCount' => 5, 'authorizedCount' => count($servers), 'summaryFields' => array_keys($summaries[1]), 'dynamicPortInRange' => true, 'credentialsExcludedFromEvidence' => true];

    \App\Models\Notice::create(['title' => 'visible', 'content' => 'fixture', 'show' => true]);
    \App\Models\Notice::create(['title' => 'hidden', 'content' => 'fixture', 'show' => false]);
    $notices = (new \App\Http\Controllers\V1\User\NoticeController())->fetch(\Illuminate\Http\Request::create('/'));
    $noticeBody = json_decode($notices->getContent(), true);
    check('notice_response_uses_data_total_not_success_envelope', $noticeBody['total'] === 1 && count($noticeBody['data']) === 1 && !isset($noticeBody['status']));
    $ticketService = new \App\Services\TicketService();
    $ticket = $ticketService->createTicket($user->id, 'fixture-ticket', 1, 'fixture-message');
    check('ticket_create_and_reply_service_contract', $ticket->exists && $ticketService->reply($ticket, 'fixture-reply', $user->id) instanceof \App\Models\TicketMessage);
    $request = \Illuminate\Http\Request::create('/', 'GET', ['id' => 999999]);
    $request->setUserResolver(fn () => $user);
    $nullLoadRisk = false;
    try { (new \App\Http\Controllers\V1\User\TicketController())->fetch($request); }
    catch (\Error $error) { $nullLoadRisk = str_contains($error->getMessage(), 'load() on null'); }
    check('ticket_missing_id_null_dereference_risk_reproduced', $nullLoadRisk);

    $configPath = '/audit/plugins/Task005Probe/config.json';
    $pluginConfig = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
    $pluginConfig['version'] = '1.0.1';
    file_put_contents($configPath, json_encode($pluginConfig, JSON_THROW_ON_ERROR));
    $manager->update('task005_probe');
    $updated = \App\Models\Plugin::where('code', 'task005_probe')->firstOrFail();
    check('plugin_update_migration_hook_version_and_reenable', $updated->version === '1.0.1' && $updated->is_enabled && in_array('update:1.0.0:1.0.1', \Plugin\Task005Probe\Plugin::$calls, true));
    $manager->disable('task005_probe');
    check('plugin_disable_updates_flag_and_cleanup', !\App\Models\Plugin::where('code', 'task005_probe')->value('is_enabled') && in_array('cleanup', \Plugin\Task005Probe\Plugin::$calls, true));
    $manager->uninstall('task005_probe');
    check('plugin_uninstall_removes_own_table_and_record', !\Illuminate\Support\Facades\Schema::hasTable('task005_probe') && !\App\Models\Plugin::where('code', 'task005_probe')->exists());
} catch (\Throwable $error) {
    check('audit_completed', false, ['exception' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
}
$passed = count(array_filter($tests, fn ($test) => !$test['passed'])) === 0;
echo json_encode(['schemaVersion' => 1, 'taskId' => 'TASK-005', 'evidenceClass' => 'isolated-upstream-runtime',
    'sourceCommit' => '4f48e61a2cbc6db5338872b6bdb45ef954ec1256', 'generatedAt' => gmdate(DATE_ATOM),
    'status' => $passed ? '通过' : '失败', 'tests' => $tests, 'snapshot' => $snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
exit($passed ? 0 : 1);
