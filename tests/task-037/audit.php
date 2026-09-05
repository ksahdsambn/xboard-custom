<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task037.sqlite',
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
$secrets = [];
function check(string $name, bool $passed, array $details = []): void
{
    global $tests;
    $tests[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
}

function httpRequest(string $method, string $path, array $headers = [], ?array $json = null): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
    foreach ($headers as $key => $value) {
        $server[$key] = $value;
    }
    $content = null;
    if ($json !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        $content = json_encode($json);
    }
    $request = \Illuminate\Http\Request::create($path, $method, [], [], [], $server, $content);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}

function bodyOf(\Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true) ?: [];
}

function makePlan(string $name, int $groupId = 2501): \App\Models\Plan
{
    $plan = new \App\Models\Plan();
    $plan->name = $name;
    $plan->group_id = $groupId;
    $plan->transfer_enable = 128;
    $plan->show = true;
    $plan->sell = true;
    $plan->renew = true;
    $plan->capacity_limit = 10000;
    $plan->prices = ['monthly' => 10];
    $plan->sort = 1;
    $plan->save();
    return $plan;
}

function makeUser(string $email, string $password): \App\Models\User
{
    $user = (new \App\Services\UserService())->createUser(['email' => $email, 'password' => $password]);
    $user->save();
    return $user;
}

function loginToken(string $email, string $password, string $prefix = '/api/mobile/v1'): string
{
    global $secrets;
    $login = httpRequest('POST', $prefix . '/auth/login', [], ['email' => $email, 'password' => $password]);
    $token = (string) (bodyOf($login)['data']['sanctumToken'] ?? '');
    $secrets[] = $token;
    return $token;
}

function authHeaders(string $token): array
{
    return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
}

function compatibleSettings(): array
{
    return [
        'tls' => 2,
        'network' => 'tcp',
        'flow' => 'xtls-rprx-vision',
        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        'reality_settings' => [
            'server_name' => 'www.example.invalid',
            'public_key' => 'fixture-public-key-task037',
            'short_id' => 'ab12',
            'private_key' => 'fixture-private-key-must-never-leave-037',
            'allow_insecure' => false,
        ],
    ];
}

function makeServer(): \App\Models\Server
{
    $server = new \App\Models\Server();
    $server->type = 'vless';
    $server->name = 'e2e-node';
    $server->host = '203.0.113.37';
    $server->port = '443';
    $server->server_port = 443;
    $server->group_ids = ['2501'];
    $server->show = true;
    $server->rate = 1;
    $server->tags = ['hk'];
    $server->sort = 1;
    $server->u = 0;
    $server->d = 0;
    $server->transfer_enable = 0;
    $server->protocol_settings = compatibleSettings();
    $server->save();
    return $server;
}

function rtdnPayload(string $eventId, string $purchaseToken): array
{
    $decoded = [
        'version' => '1.0',
        'packageName' => 'dev.xboard.xboard_mobile',
        'eventTimeMillis' => (string) (time() * 1000),
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 2,
            'purchaseToken' => $purchaseToken,
            'subscriptionId' => 'dev.xboard.sub.monthly',
        ],
    ];
    return [
        'message' => [
            'messageId' => $eventId,
            'publishTime' => '2026-09-06T00:00:00Z',
            'attributes' => ['environment' => 'sandbox'],
            'data' => base64_encode(json_encode($decoded)),
        ],
        'subscription' => 'projects/xboard-dev/subscriptions/play-rtdn',
    ];
}

function postRtdn(array $payload, string $path = '/api/mobile/v1/platform/google/rtdn'): \Symfony\Component\HttpFoundation\Response
{
    $raw = json_encode($payload);
    $headers = [
        'HTTP_X_MOBILE_RTDN_TEST' => 'fixture-ok',
        'HTTP_X_GOOG_CHANNEL_TOKEN' => 'rtdn-sandbox-channel',
        'HTTP_X_MOBILE_RTDN_SIGNATURE' => hash('sha256', 'rtdn-sandbox-channel.' . $raw),
    ];
    return httpRequest('POST', $path, $headers, $payload);
}

try {
    $started = microtime(true);
    $app = require '/audit/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    \Illuminate\Support\Facades\Queue::fake();

    $manager = new \App\Services\Plugin\PluginManager();
    $manager->install('mobile_app');
    $manager->enable('mobile_app');
    try {
        $manager->install('wallet_center');
        $manager->enable('wallet_center');
    } catch (\Throwable) {
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--path' => 'plugins/WalletCenter/database/migrations',
            '--force' => true,
        ]);
    }

    admin_setting([
        'captcha_enable' => 0,
        'stop_register' => 0,
        'invite_force' => 0,
        'email_verify' => 0,
        'email_whitelist_enable' => 0,
        'email_gmail_limit_enable' => 0,
        'password_limit_enable' => 0,
        'register_limit_by_ip_enable' => 0,
    ]);

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::reset();
    $password = 'task037-pass';
    $users = new \App\Services\UserService();
    $plan = makePlan('e2e-plan', 2501);
    $admin = makeUser('admin-' . bin2hex(random_bytes(3)) . '@example.invalid', $password);
    $admin->is_admin = true;
    $admin->save();
    $adminAuth = authHeaders(loginToken($admin->email, $password));
    httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $plan->id,
        'enabled' => true,
    ]);

    $user = makeUser('user-' . bin2hex(random_bytes(3)) . '@example.invalid', $password);
    $users->assignPlan($user, $plan, 30);
    $user->save();
    $secrets[] = (string) $user->uuid;
    $server = makeServer();
    $opaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $server->id);
    $userAuth = authHeaders(loginToken($user->email, $password));
    $obf = 'obf-' . bin2hex(random_bytes(4));

    $boot = httpRequest('GET', '/api/mobile/v1/bootstrap');
    $bootV0 = httpRequest('GET', '/api/mobile/v0/bootstrap');
    check(
        'bootstrap_available_on_v0_and_v1',
        $boot->getStatusCode() === 200 && $bootV0->getStatusCode() === 200 && (bodyOf($boot)['status'] ?? null) === 'success',
        ['v1' => $boot->getStatusCode(), 'v0' => $bootV0->getStatusCode()]
    );
    $account = httpRequest('GET', '/api/mobile/v1/account', $userAuth);
    $ent = httpRequest('GET', '/api/mobile/v1/entitlement', $userAuth);
    $plans = httpRequest('GET', '/api/mobile/v1/plans', $userAuth);
    $nodes = httpRequest('GET', '/api/mobile/v1/nodes', $userAuth);
    $profile = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $userAuth);
    $notices = httpRequest('GET', '/api/mobile/v1/notices', $userAuth);
    $ticket = httpRequest('POST', '/api/mobile/v1/tickets', $userAuth, ['subject' => 'e2e', 'message' => 'help', 'level' => 2]);
    $device = httpRequest('PUT', '/api/mobile/v1/devices', $userAuth, [
        'opaqueDeviceId' => 'dev-' . bin2hex(random_bytes(4)),
        'platform' => 'android',
        'appVersion' => '1.0.0',
        'androidApi' => 26,
        'mobileApiVersion' => 1,
        'profileSchemaVersion' => 1,
        'libxrayVersion' => 'dev',
        'xrayCoreVersion' => 'dev',
    ]);
    $buy = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $rtdn = postRtdn(rtdnPayload('evt-e2e-1', 'tok-purchased'));
    $deletion = httpRequest('POST', '/api/mobile/v1/account/deletion/preview', $userAuth);
    $entAfter = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $userAuth));
    $profileJson = (string) $profile->getContent();
    check(
        'v1_full_user_journey_succeeds',
        $boot->getStatusCode() === 200
        && $account->getStatusCode() === 200
        && $ent->getStatusCode() === 200
        && $plans->getStatusCode() === 200
        && $nodes->getStatusCode() === 200
        && $profile->getStatusCode() === 200
        && $notices->getStatusCode() === 200
        && $ticket->getStatusCode() === 200
        && $device->getStatusCode() === 200
        && $buy->getStatusCode() === 200
        && $rtdn->getStatusCode() === 200
        && $deletion->getStatusCode() === 200
        && ($entAfter['data']['playManaged'] ?? null) === true
        && ($entAfter['data']['walletAutoRenewBlocked'] ?? null) === true
        && !str_contains($profileJson, 'fixture-private-key-must-never-leave-037'),
        ['profile' => $profile->getStatusCode(), 'buy' => $buy->getStatusCode(), 'rtdn' => $rtdn->getStatusCode()]
    );

    $v0Auth = authHeaders(loginToken($user->email, $password, '/api/mobile/v0'));
    $v0Account = httpRequest('GET', '/api/mobile/v0/account', $v0Auth);
    $v0Nodes = httpRequest('GET', '/api/mobile/v0/nodes', $v0Auth);
    $v0Profile = httpRequest('GET', '/api/mobile/v0/profiles/' . $opaque, $v0Auth);
    $v0Buy = httpRequest('POST', '/api/mobile/v0/play/purchases', $v0Auth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    check(
        'v0_previous_contract_repeats_key_flows',
        $v0Account->getStatusCode() === 200
        && $v0Nodes->getStatusCode() === 200
        && $v0Profile->getStatusCode() === 200
        && $v0Buy->getStatusCode() === 200
        && \Plugin\MobileApp\Models\PurchaseToken::query()->where('purchase_token_hash', hash('sha256', 'tok-purchased'))->count() === 1,
        ['account' => $v0Account->getStatusCode(), 'profile' => $v0Profile->getStatusCode()]
    );

    $user->refresh();
    $expire = (int) ($user->expired_at ?? 0);
    $dupBuy = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $lateRtdn = postRtdn(rtdnPayload('evt-e2e-late', 'tok-purchased'));
    $earlyRtdn = postRtdn(rtdnPayload('evt-e2e-early', 'tok-purchased'));
    $user->refresh();
    check(
        'duplicate_purchase_and_rtdn_do_not_repeat_entitlement',
        $dupBuy->getStatusCode() === 200
        && $lateRtdn->getStatusCode() === 200
        && $earlyRtdn->getStatusCode() === 200
        && (int) ($user->expired_at ?? 0) === $expire
        && \Plugin\MobileApp\Models\PurchaseToken::query()->count() === 1,
        ['expire' => $expire, 'ledgers' => \Plugin\MobileApp\Models\PurchaseToken::query()->count()]
    );

    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['maintenance' => true]);
    $maint = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $userAuth);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['maintenance' => false]);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, [
        'forceUpgradeEnabled' => true,
        'forceUpgradeReason' => 'security-vulnerability',
        'forceUpgradeEvidenceRef' => 'advisory-e2e',
        'forceUpgradeApprovedBy' => 'security-owner',
    ]);
    $force = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $userAuth);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['forceUpgradeEnabled' => false]);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, [
        'disabledKernelVersions' => [['libxray' => 'bad-lib', 'xrayCore' => 'bad-core']],
    ]);
    $kernel = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $userAuth + [
        'HTTP_X_LIBXRAY_VERSION' => 'bad-lib',
        'HTTP_X_XRAY_CORE_VERSION' => 'bad-core',
    ]);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['disabledKernelVersions' => []]);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['purchaseEnabled' => false]);
    $paused = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => 'obf-pause',
    ]);
    httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, ['purchaseEnabled' => true]);
    $pauseCode = bodyOf($paused)['errorCode'] ?? null;
    check(
        'degradation_states_return_stable_machine_codes',
        (bodyOf($maint)['errorCode'] ?? null) === 'SERVICE_MAINTENANCE'
        && (bodyOf($force)['errorCode'] ?? null) === 'FORCE_UPGRADE'
        && (bodyOf($kernel)['errorCode'] ?? null) === 'KERNEL_VERSION_DISABLED'
        && $pauseCode !== null
        && $paused->getStatusCode() >= 400,
        ['maint' => bodyOf($maint)['errorCode'] ?? null, 'force' => bodyOf($force)['errorCode'] ?? null, 'kernel' => bodyOf($kernel)['errorCode'] ?? null, 'paused' => $pauseCode]
    );

    $manager->disable('mobile_app');
    $disabled = httpRequest('GET', '/api/mobile/v1/account', $userAuth);
    $web = httpRequest('POST', '/api/v1/passport/auth/login');
    check(
        'disabling_mobile_app_keeps_xboard_web_login',
        in_array($disabled->getStatusCode(), [404, 503], true)
        && $web->getStatusCode() !== 404
        && $web->getStatusCode() !== 500,
        ['mobile' => $disabled->getStatusCode(), 'web' => $web->getStatusCode()]
    );

    $elapsedMs = (int) round((microtime(true) - $started) * 1000);
    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $secretHit = \Plugin\MobileApp\Support\MobileLogRedactor::encodedContainsSensitive($sink, array_values(array_filter($secrets)));
    check(
        'e2e_latency_and_logs_stay_within_baseline',
        $elapsedMs < 10000 && $secretHit === false,
        ['ms' => $elapsedMs, 'formalAcceptanceClaimed' => false]
    );

    echo json_encode([
        'taskId' => 'TASK-037',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'realProductionBackendClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-037',
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
