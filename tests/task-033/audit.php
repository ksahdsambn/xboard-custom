<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task033.sqlite',
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

function rtdnPayload(string $eventId, string $purchaseToken, string $environment = 'sandbox'): array
{
    $decoded = [
        'version' => '1.0',
        'packageName' => 'dev.xboard.xboard_mobile',
        'eventTimeMillis' => (string) (time() * 1000),
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 12,
            'purchaseToken' => $purchaseToken,
            'subscriptionId' => 'dev.xboard.sub.monthly',
        ],
    ];
    return [
        'message' => [
            'messageId' => $eventId,
            'publishTime' => '2026-09-06T00:00:00Z',
            'attributes' => ['environment' => $environment],
            'data' => base64_encode(json_encode($decoded)),
        ],
        'subscription' => 'projects/xboard-dev/subscriptions/play-rtdn',
    ];
}

function postRtdn(array $payload): \Symfony\Component\HttpFoundation\Response
{
    $raw = json_encode($payload);
    $headers = [
        'HTTP_X_MOBILE_RTDN_TEST' => 'fixture-ok',
        'HTTP_X_GOOG_CHANNEL_TOKEN' => 'rtdn-sandbox-channel',
        'HTTP_X_MOBILE_RTDN_SIGNATURE' => hash('sha256', 'rtdn-sandbox-channel.' . $raw),
    ];
    return httpRequest('POST', '/api/mobile/v1/platform/google/rtdn', $headers, $payload);
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
    $password = 'task033-pass';
    $webPlan = makePlan('web-long');
    $playPlan = makePlan('play-mapped');
    $admin = makeUser('admin-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $admin->is_admin = true;
    $admin->save();
    $webUser = makeUser('web-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $playUser = makeUser('play-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $adminAuth = authHeaders(loginToken($admin->email, $password));
    $webAuth = authHeaders(loginToken($webUser->email, $password));
    $playAuth = authHeaders(loginToken($playUser->email, $password));

    $webExpiry = 4102444800;
    $webUser->plan_id = $webPlan->id;
    $webUser->group_id = $webPlan->group_id;
    $webUser->expired_at = $webExpiry;
    $webUser->transfer_enable = 50;
    $webUser->save();

    httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $playPlan->id,
        'enabled' => true,
    ]);

    $obfWeb = 'obf-web-' . bin2hex(random_bytes(4));
    $first = httpRequest('POST', '/api/mobile/v1/play/purchases', $webAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obfWeb,
        'planId' => 99,
        'expiresAt' => 1,
    ]);
    $webUser->refresh();
    $ent = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $webAuth));
    $active = \Plugin\MobileApp\Models\EntitlementProjection::query()->where('user_id', $webUser->id)->where('status', 'active')->count();
    check(
        'web_long_grant_not_shortened_and_play_source',
        $first->getStatusCode() === 200
        && (int) $webUser->expired_at === $webExpiry
        && (int) $webUser->plan_id === (int) $webPlan->id
        && ($ent['data']['source'] ?? null) === 'play'
        && ($ent['data']['playManaged'] ?? null) === true
        && $active === 1,
        ['expired' => $webUser->expired_at, 'source' => $ent['data']['source'] ?? null, 'active' => $active]
    );

    $dup = httpRequest('POST', '/api/mobile/v1/play/purchases', $webAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obfWeb,
    ]);
    $dupV0 = httpRequest('POST', '/api/mobile/v0/play/purchases', $webAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obfWeb,
    ]);
    $projCount = \Plugin\MobileApp\Models\EntitlementProjection::query()->where('user_id', $webUser->id)->count();
    $webUser->refresh();
    check(
        'duplicate_purchase_and_v0_do_not_restack',
        $dup->getStatusCode() === 200
        && $dupV0->getStatusCode() === 200
        && $projCount === 1
        && (int) $webUser->expired_at === $webExpiry
        && (int) $webUser->transfer_enable >= 50,
        ['projections' => $projCount, 'expired' => $webUser->expired_at]
    );

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->setStatus('tok-purchased', 'refunded');
    $refund = postRtdn(rtdnPayload('evt-refund', 'tok-purchased'));
    $webUser->refresh();
    $activeAfter = \Plugin\MobileApp\Models\EntitlementProjection::query()->where('user_id', $webUser->id)->where('status', 'active')->count();
    $entAfter = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $webAuth));
    check(
        'refund_restores_web_baseline',
        $refund->getStatusCode() === 200
        && (int) $webUser->expired_at === $webExpiry
        && (int) $webUser->plan_id === (int) $webPlan->id
        && $activeAfter === 0
        && ($entAfter['data']['source'] ?? null) === 'web',
        ['expired' => $webUser->expired_at, 'source' => $entAfter['data']['source'] ?? null, 'active' => $activeAfter]
    );

    $obfPlay = 'obf-play-' . bin2hex(random_bytes(4));
    $playBefore = $playUser->plan_id;
    $buy = httpRequest('POST', '/api/mobile/v1/play/purchases', $playAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-grace',
        'obfuscatedAccountId' => $obfPlay,
    ]);
    $playUser->refresh();
    $playEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $playAuth));
    check(
        'play_only_user_gets_plan_and_play_source',
        $buy->getStatusCode() === 200
        && ($playBefore === null || (int) $playBefore === 0)
        && (int) $playUser->plan_id === (int) $playPlan->id
        && ($playEnt['data']['source'] ?? null) === 'play'
        && ($playEnt['data']['connectAllowed'] ?? null) === true,
        ['plan' => $playUser->plan_id, 'source' => $playEnt['data']['source'] ?? null]
    );

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->setStatus('tok-grace', 'revoked');
    $revoked = postRtdn(rtdnPayload('evt-revoke', 'tok-grace'));
    $playUser->refresh();
    $playEnt2 = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $playAuth));
    check(
        'revoke_play_only_clears_to_baseline',
        $revoked->getStatusCode() === 200
        && (int) ($playUser->plan_id ?? 0) === 0
        && ($playEnt2['data']['source'] ?? null) === 'none',
        ['plan' => $playUser->plan_id, 'source' => $playEnt2['data']['source'] ?? null]
    );

    $restore = httpRequest('POST', '/api/mobile/v1/play/purchases/restore', $playAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => $obfPlay,
    ]);
    $playUser->refresh();
    $playEnt3 = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $playAuth));
    check(
        'restore_reprojects_grantable_status',
        $restore->getStatusCode() === 200
        && (bodyOf($restore)['data']['restored'] ?? null) === true
        && (int) $playUser->plan_id === (int) $playPlan->id
        && ($playEnt3['data']['source'] ?? null) === 'play',
        ['restored' => bodyOf($restore)['data']['restored'] ?? null, 'source' => $playEnt3['data']['source'] ?? null]
    );

    $canceled = httpRequest('POST', '/api/mobile/v1/play/purchases', $playAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-canceled',
        'obfuscatedAccountId' => $obfPlay,
    ]);
    $canceledRow = \Plugin\MobileApp\Models\EntitlementProjection::query()
        ->where('purchase_token_id', \Plugin\MobileApp\Models\PurchaseToken::query()->where('purchase_token_hash', hash('sha256', 'tok-canceled'))->value('id'))
        ->latest('id')
        ->first();
    check(
        'canceled_still_active_until_expiry',
        $canceled->getStatusCode() === 200
        && $canceledRow instanceof \Plugin\MobileApp\Models\EntitlementProjection
        && $canceledRow->status === 'active',
        ['status' => $canceledRow->status ?? null]
    );

    $encoded = json_encode([$ent, $playEnt3], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'entitlement_api_omits_raw_tokens_and_uses_service',
        !str_contains($encoded, 'tok-purchased')
        && !str_contains($encoded, 'tok-grace')
        && isset($ent['data']['connectAllowed'])
        && isset($ent['data']['playManaged']),
        ['hasConnect' => isset($ent['data']['connectAllowed'])]
    );

    echo json_encode([
        'taskId' => 'TASK-033',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-033',
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
