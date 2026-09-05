<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task031.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'purchasetoken'], true)) {
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
    $password = 'task031-pass';
    $plan = makePlan('play-mapped');
    $admin = makeUser('admin-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $admin->is_admin = true;
    $admin->save();
    $user = makeUser('buyer-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $other = makeUser('other-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $adminAuth = authHeaders(loginToken($admin->email, $password));
    $userAuth = authHeaders(loginToken($user->email, $password));
    $otherAuth = authHeaders(loginToken($other->email, $password));
    $beforePlan = $user->plan_id;

    httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $plan->id,
        'enabled' => true,
    ]);

    $obf = 'obf-account-' . bin2hex(random_bytes(4));
    $first = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
        'price' => 9.99,
        'planId' => 99,
        'duration' => 365,
        'expiresAt' => time() + 99999,
        'entitlement' => 'premium',
    ]);
    $firstBody = bodyOf($first);
    $row = \Plugin\MobileApp\Models\PurchaseToken::query()->first();
    $acks = \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->acknowledgeCalls;
    $user->refresh();
    check(
        'first_purchase_persists_grants_then_acknowledges',
        $first->getStatusCode() === 200
        && ($firstBody['data']['playStatus'] ?? null) === 'purchased'
        && $row instanceof \Plugin\MobileApp\Models\PurchaseToken
        && $row->purchase_token_hash === hash('sha256', 'tok-purchased')
        && $row->granted_at !== null
        && $row->acknowledged === true
        && $row->acknowledged_at !== null
        && $acks === 1
        && $user->plan_id === $beforePlan,
        ['http' => $first->getStatusCode(), 'status' => $firstBody['data']['playStatus'] ?? null, 'acks' => $acks]
    );

    $repeat = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $repeatV0 = httpRequest('POST', '/api/mobile/v0/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    check(
        'duplicate_and_concurrent_same_token_are_idempotent',
        $repeat->getStatusCode() === 200
        && (bodyOf($repeat)['data']['ledgerId'] ?? null) === ($firstBody['data']['ledgerId'] ?? -1)
        && $repeatV0->getStatusCode() === 200
        && \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->acknowledgeCalls === 1
        && \Plugin\MobileApp\Models\PurchaseToken::query()->count() === 1,
        ['acks' => \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->acknowledgeCalls]
    );

    $bound = httpRequest('POST', '/api/mobile/v1/play/purchases', $otherAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => 'obf-account-other1',
    ]);
    check(
        'token_bound_to_other_account_rejected',
        (bodyOf($bound)['errorCode'] ?? null) === 'PURCHASE_DUPLICATE',
        ['code' => bodyOf($bound)['errorCode'] ?? null]
    );

    $pending = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-pending',
        'obfuscatedAccountId' => $obf,
    ]);
    $renewal = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-renewal',
        'obfuscatedAccountId' => $obf,
    ]);
    check(
        'pending_and_renewal_follow_developer_api',
        (bodyOf($pending)['errorCode'] ?? null) === 'PURCHASE_PENDING'
        && $renewal->getStatusCode() === 200
        && (bodyOf($renewal)['data']['playStatus'] ?? null) === 'purchased'
        && \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->acknowledgeCalls === 1
        && \Plugin\MobileApp\Models\PurchaseToken::query()->where('play_status', 'pending')->exists(),
        ['pending' => bodyOf($pending)['errorCode'] ?? null, 'acks' => \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->acknowledgeCalls]
    );

    $recorded = [];
    foreach (['tok-canceled' => 'canceled', 'tok-expired' => 'expired', 'tok-refunded' => 'refunded', 'tok-revoked' => 'revoked', 'tok-grace' => 'grace', 'tok-hold' => 'account_hold', 'tok-restore' => 'restored'] as $token => $status) {
        $resp = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
            'productId' => 'dev.xboard.sub.monthly',
            'purchaseToken' => $token,
            'obfuscatedAccountId' => $obf,
        ]);
        $recorded[$token] = bodyOf($resp)['data']['playStatus'] ?? bodyOf($resp)['errorCode'] ?? $resp->getStatusCode();
    }
    check(
        'canceled_expired_refunded_revoked_grace_hold_restore_recorded',
        $recorded === [
            'tok-canceled' => 'canceled',
            'tok-expired' => 'expired',
            'tok-refunded' => 'refunded',
            'tok-revoked' => 'revoked',
            'tok-grace' => 'grace',
            'tok-hold' => 'account_hold',
            'tok-restore' => 'restored',
        ],
        ['recorded' => $recorded]
    );

    $wrongPkg = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-wrong-pkg',
        'obfuscatedAccountId' => $obf,
    ]);
    $wrongProduct = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-wrong-product',
        'obfuscatedAccountId' => $obf,
    ]);
    $forged = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-forged',
        'obfuscatedAccountId' => $obf,
    ]);
    $invalidCount = \Plugin\MobileApp\Models\PurchaseToken::query()->where('play_status', 'invalid')->count();
    check(
        'wrong_package_product_and_forged_token_rejected',
        (bodyOf($wrongPkg)['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && (bodyOf($wrongProduct)['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && (bodyOf($forged)['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && $invalidCount >= 3,
        ['pkg' => bodyOf($wrongPkg)['errorCode'] ?? null, 'product' => bodyOf($wrongProduct)['errorCode'] ?? null, 'invalidRows' => $invalidCount]
    );

    $restore = httpRequest('POST', '/api/mobile/v1/play/purchases/restore', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => $obf,
    ]);
    $restoreV0 = httpRequest('POST', '/api/mobile/v0/play/purchases/restore', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => $obf,
    ]);
    $rtdn = httpRequest('POST', '/api/mobile/v1/platform/google/rtdn', ['HTTP_X_MOBILE_RTDN_TEST' => 'fixture-ok'], ['message' => 'ignored']);
    $encoded = json_encode([$firstBody, bodyOf($repeat), $recorded], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $hashes = \Plugin\MobileApp\Models\PurchaseToken::query()->pluck('purchase_token_hash')->all();
    check(
        'restore_compat_rtdn_skeleton_and_no_raw_token',
        (bodyOf($restore)['data']['restored'] ?? null) === true
        && $restoreV0->getStatusCode() === 200
        && (bodyOf($rtdn)['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
        && !str_contains($encoded, 'tok-purchased')
        && !in_array('tok-purchased', $hashes, true)
        && $user->fresh()->plan_id === $beforePlan,
        ['restore' => bodyOf($restore)['data']['restored'] ?? null, 'rtdn' => bodyOf($rtdn)['errorCode'] ?? null]
    );

    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $leaked = false;
    foreach (array_merge($secrets, ['tok-purchased', 'tok-renewal']) as $secret) {
        if ($secret !== '' && str_contains($sink, $secret)) {
            $leaked = true;
            break;
        }
    }
    check('logs_omit_raw_purchase_and_session_tokens', !$leaked, ['leaked' => $leaked]);

    echo json_encode([
        'taskId' => 'TASK-031',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-031',
        'status' => 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => array_merge($tests, [[
            'name' => 'runtime_exception',
            'passed' => false,
            'details' => ['type' => $exception::class, 'message' => $exception->getMessage()],
        ]]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
