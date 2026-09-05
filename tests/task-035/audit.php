<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task035.sqlite',
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
    $safe = [];
    foreach ($details as $key => $value) {
        $lower = strtolower((string) $key);
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'purchasetoken', 'uuid'], true)) {
            $safe[$key] = '[redacted]';
            continue;
        }
        if (is_string($value) && (str_starts_with($value, 'Bearer ') || strlen($value) > 80)) {
            $safe[$key] = '[redacted]';
            continue;
        }
        $safe[$key] = $value;
    }
    $tests[] = ['name' => $name, 'passed' => $passed, 'details' => $safe];
}

function httpRequest(string $method, string $path, array $headers = [], ?array $json = null, ?string $raw = null): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
    foreach ($headers as $key => $value) {
        $server[$key] = $value;
    }
    $content = $raw;
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

function makePlan(string $name): \App\Models\Plan
{
    $plan = new \App\Models\Plan();
    $plan->name = $name;
    $plan->group_id = 1;
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

function loginToken(string $email, string $password): string
{
    global $secrets;
    $login = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $password]);
    $token = (string) (bodyOf($login)['data']['sanctumToken'] ?? '');
    $secrets[] = $token;
    return $token;
}

function authHeaders(string $token): array
{
    return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
}

try {
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
    \Plugin\MobileApp\Support\DownstreamRetryPolicy::reset();
    \Plugin\MobileApp\Support\MobileObservability::reset();
    \Plugin\MobileApp\Support\MobileLogRedactor::$sink = [];

    $password = 'task035-pass';
    $users = new \App\Services\UserService();
    $plan = makePlan('play-mapped');
    $admin = makeUser('admin-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
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

    $user = makeUser('user-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($user, $plan, 30);
    $user->save();
    $secrets[] = (string) $user->uuid;
    $secrets[] = (string) $user->token;
    $userAuth = authHeaders(loginToken($user->email, $password));

    $unauthProfile = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/not-a-node'));
    $unauthPurchase = bodyOf(httpRequest('POST', '/api/mobile/v1/play/purchases', [], [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => 'obf-x',
    ]));
    $unauthDeletion = bodyOf(httpRequest('POST', '/api/mobile/v1/account/deletion/preview'));
    $unauthDiag = bodyOf(httpRequest('GET', '/api/mobile/v1/diagnostics'));
    check(
        'unauthorized_sensitive_operations_are_rejected',
        ($unauthProfile['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($unauthPurchase['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($unauthDeletion['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($unauthDiag['errorCode'] ?? null) === 'AUTH_SESSION_INVALID',
        ['profile' => $unauthProfile['errorCode'] ?? null, 'purchase' => $unauthPurchase['errorCode'] ?? null]
    );

    $escalation = bodyOf(httpRequest('GET', '/api/mobile/v1/admin/security-audits', $userAuth));
    check(
        'user_cannot_read_admin_security_audits',
        in_array($escalation['errorCode'] ?? null, ['AUTH_SESSION_INVALID', 'AUTH_FORBIDDEN'], true)
        && empty($escalation['data']),
        ['errorCode' => $escalation['errorCode'] ?? null]
    );

    $obf = 'obf-' . bin2hex(random_bytes(4));
    $firstBuy = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $user->refresh();
    $expireAfterFirst = (int) ($user->expired_at ?? 0);
    $ledgerCount = \Plugin\MobileApp\Models\PurchaseToken::query()->count();
    $replay = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $user->refresh();
    check(
        'purchase_replay_is_idempotent',
        $firstBuy->getStatusCode() === 200
        && $replay->getStatusCode() === 200
        && \Plugin\MobileApp\Models\PurchaseToken::query()->count() === $ledgerCount
        && (int) ($user->expired_at ?? 0) === $expireAfterFirst,
        ['ledgers' => $ledgerCount, 'http' => $replay->getStatusCode()]
    );

    \Illuminate\Support\Facades\Cache::flush();
    $rateHeaders = $userAuth + ['REMOTE_ADDR' => '203.0.113.35'];
    $codes = [];
    for ($i = 0; $i < 31; $i++) {
        $codes[] = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . hash('sha256', 'x' . $i), $rateHeaders))['errorCode'] ?? null;
    }
    check(
        'profile_rate_limit_rejects_over_quota',
        in_array('AUTH_RATE_LIMITED', $codes, true),
        ['limited' => in_array('AUTH_RATE_LIMITED', $codes, true), 'last' => $codes[30] ?? null]
    );

    $oversize = httpRequest('POST', '/api/mobile/v1/auth/login', ['REMOTE_ADDR' => '203.0.113.36'], null, str_repeat('a', 70001));
    check(
        'oversized_request_is_rejected',
        $oversize->getStatusCode() === 413
        && (bodyOf($oversize)['errorCode'] ?? null) === 'REQUEST_TOO_LARGE',
        ['http' => $oversize->getStatusCode(), 'code' => bodyOf($oversize)['errorCode'] ?? null]
    );

    \Illuminate\Support\Facades\Cache::flush();
    $badPage = bodyOf(httpRequest('GET', '/api/mobile/v1/notices?perPage=1001', $userAuth));
    $badPage2 = bodyOf(httpRequest('GET', '/api/mobile/v1/notices?page=abc', $userAuth));
    check(
        'illegal_pagination_is_rejected',
        ($badPage['errorCode'] ?? null) === 'PAGINATION_INVALID'
        && ($badPage2['errorCode'] ?? null) === 'PAGINATION_INVALID',
        ['perPage' => $badPage['errorCode'] ?? null, 'page' => $badPage2['errorCode'] ?? null]
    );

    $injection = "1' OR 1=1 --";
    $injected = httpRequest('GET', '/api/mobile/v1/profiles/' . rawurlencode($injection), $userAuth);
    $injectedBody = bodyOf($injected);
    $injectedJson = json_encode($injectedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'injection_in_opaque_profile_id_is_rejected',
        in_array($injectedBody['errorCode'] ?? null, ['PROFILE_UNAVAILABLE', 'ENTITLEMENT_NONE'], true)
        && $injected->getStatusCode() !== 500
        && !str_contains($injectedJson, 'SQLSTATE')
        && !str_contains($injectedJson, $injection),
        ['errorCode' => $injectedBody['errorCode'] ?? null, 'http' => $injected->getStatusCode()]
    );

    $probe = httpRequest('GET', '/api/mobile/v1/account?privateKey=secret-private&purchaseToken=tok-purchased&uuid=' . $user->uuid, $userAuth);
    $probeJson = (string) $probe->getContent();
    check(
        'sensitive_field_probing_does_not_echo_secrets',
        $probe->getStatusCode() === 200
        && !str_contains($probeJson, 'secret-private')
        && !str_contains($probeJson, 'tok-purchased')
        && !str_contains($probeJson, (string) $user->uuid)
        && !str_contains($probeJson, 'privateKey'),
        ['http' => $probe->getStatusCode()]
    );

    $httpsFail = httpRequest('GET', '/api/mobile/v1/bootstrap', ['HTTP_X_MOBILE_REQUIRE_HTTPS' => '1']);
    $httpsOk = httpRequest('GET', '/api/mobile/v1/bootstrap', ['HTTP_X_MOBILE_REQUIRE_HTTPS' => '1', 'HTTPS' => 'on']);
    check(
        'https_is_required_when_forced',
        (bodyOf($httpsFail)['errorCode'] ?? null) === 'HTTPS_REQUIRED'
        && $httpsFail->getStatusCode() === 403
        && $httpsOk->getStatusCode() === 200
        && (bodyOf($httpsOk)['status'] ?? null) === 'success',
        ['fail' => bodyOf($httpsFail)['errorCode'] ?? null, 'ok' => $httpsOk->getStatusCode()]
    );

    $diag = httpRequest('GET', '/api/mobile/v1/diagnostics', $userAuth + [
        'HTTP_X_APP_VERSION' => '1.0.0',
        'HTTP_X_LIBXRAY_VERSION' => 'libxray-dev',
        'HTTP_X_XRAY_CORE_VERSION' => 'xray-dev',
    ]);
    $diagBody = bodyOf($diag);
    $diagV0 = httpRequest('GET', '/api/mobile/v0/diagnostics', $userAuth);
    check(
        'diagnostics_are_user_scoped_and_secret_free',
        $diag->getStatusCode() === 200
        && ($diagBody['data']['mobileApiVersion'] ?? null) === 1
        && ($diagBody['data']['profileSchemaVersion'] ?? null) === 1
        && !isset($diagBody['data']['sanctumToken'])
        && $diagV0->getStatusCode() === 200
        && !str_contains((string) $diag->getContent(), (string) $user->uuid),
        ['http' => $diag->getStatusCode(), 'v0' => $diagV0->getStatusCode()]
    );

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::reset();
    \Plugin\MobileApp\Support\DownstreamRetryPolicy::reset();
    $stormUser = makeUser('storm-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($stormUser, $plan, 30);
    $stormUser->save();
    $stormAuth = authHeaders(loginToken($stormUser->email, $password));
    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->failNext(10);
    $storm = httpRequest('POST', '/api/mobile/v1/play/purchases', $stormAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => 'obf-' . bin2hex(random_bytes(4)),
    ]);
    $stormAttempts = \Plugin\MobileApp\Support\DownstreamRetryPolicy::attemptsFor('play_developer_lookup');
    $stormExpire = (int) ($stormUser->fresh()->expired_at ?? 0);
    \Plugin\MobileApp\Support\DownstreamRetryPolicy::reset();
    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->failNext(2);
    $recovered = httpRequest('POST', '/api/mobile/v1/play/purchases', $stormAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => 'obf-' . bin2hex(random_bytes(4)),
    ]);
    $recoveredAttempts = \Plugin\MobileApp\Support\DownstreamRetryPolicy::attemptsFor('play_developer_lookup');
    $afterRecover = (int) ($stormUser->fresh()->expired_at ?? 0);
    $restoreLedgers = \Plugin\MobileApp\Models\PurchaseToken::query()->where('purchase_token_hash', hash('sha256', 'tok-restore'))->count();
    check(
        'downstream_timeout_retries_are_capped_and_idempotent',
        (bodyOf($storm)['errorCode'] ?? null) === 'DOWNSTREAM_UNAVAILABLE'
        && $stormAttempts === 3
        && $recovered->getStatusCode() === 200
        && $recoveredAttempts === 3
        && $afterRecover >= $stormExpire
        && $restoreLedgers === 1,
        ['stormAttempts' => $stormAttempts, 'recoveredAttempts' => $recoveredAttempts, 'ledgers' => $restoreLedgers]
    );

    \Plugin\MobileApp\Support\MobileLogRedactor::error('probe', [
        'purchaseToken' => 'tok-purchased',
        'privateKey' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----",
        'profileRejectReason' => 'unauthorized_or_unknown',
        'entitlementDiff' => ['fromExpire' => 1, 'toExpire' => 2],
        'purchaseVerification' => 'purchased',
    ]);
    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $metrics = json_encode(\Plugin\MobileApp\Support\MobileObservability::$metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $auditRows = \Plugin\MobileApp\Models\SecurityAudit::query()->get()->toJson();
    $blob = $sink . $metrics . $auditRows;
    $secretHit = \Plugin\MobileApp\Support\MobileLogRedactor::encodedContainsSensitive($blob, array_values(array_filter($secrets)));
    $ops = \Plugin\MobileApp\Models\SecurityAudit::query()->pluck('operation')->unique()->sort()->values()->all();
    $adminAudits = bodyOf(httpRequest('GET', '/api/mobile/v1/admin/security-audits', $adminAuth));
    check(
        'logs_metrics_and_audits_are_redacted_and_complete',
        $secretHit === false
        && str_contains($sink, 'profileRejectReason')
        && str_contains($sink, 'entitlementDiff')
        && in_array('auth', $ops, true)
        && in_array('purchase', $ops, true)
        && in_array('diagnostic', $ops, true)
        && ($adminAudits['status'] ?? null) === 'success'
        && !str_contains(json_encode($adminAudits), 'tok-purchased'),
        ['ops' => $ops, 'secretHit' => $secretHit]
    );

    echo json_encode([
        'taskId' => 'TASK-035',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'penetrationTestClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-035',
        'status' => 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'penetrationTestClaimed' => false,
        'tests' => array_merge($tests, [[
            'name' => 'runtime_exception',
            'passed' => false,
            'details' => ['type' => $exception::class, 'message' => $exception->getMessage()],
        ]]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
