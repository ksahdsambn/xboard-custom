<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task023.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'auth_data', 'subscriptiontoken', 'uuid'], true)) {
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

function httpRequest(string $method, string $path, array $headers = [], ?array $json = null, array $query = []): \Symfony\Component\HttpFoundation\Response
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
    $request = \Illuminate\Http\Request::create($path, $method, $query, [], [], $server, $content);
    if ($json !== null) {
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');
    }
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}

function bodyOf(\Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true) ?: [];
}

function makePlan(string $name, bool $show = true, bool $sell = true): \App\Models\Plan
{
    $plan = new \App\Models\Plan();
    $plan->name = $name;
    $plan->group_id = 1;
    $plan->transfer_enable = 128;
    $plan->show = $show;
    $plan->sell = $sell;
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

    $password = 'task023-pass';
    $users = new \App\Services\UserService();
    $mapped = makePlan('play-mapped');
    $unmapped = makePlan('web-unmapped');
    \Plugin\MobileApp\Models\PlayProduct::query()->create([
        'package_name' => 'dev.xboard.xboard_mobile',
        'product_id' => 'dev.xboard.sub.monthly',
        'base_plan_id' => 'p1m',
        'environment' => 'sandbox',
        'xboard_plan_id' => $mapped->id,
        'enabled' => true,
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $webUser = makeUser('web-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($webUser, $mapped, 30);
    $webUser->u = 1024;
    $webUser->d = 2048;
    $webUser->save();
    $secrets[] = (string) $webUser->token;
    $secrets[] = (string) $webUser->uuid;
    $webToken = loginToken($webUser->email, $password);
    $webAuth = authHeaders($webToken);
    $account = httpRequest('GET', '/api/mobile/v1/account', $webAuth);
    $accountBody = bodyOf($account);
    $entitlement = httpRequest('GET', '/api/mobile/v1/entitlement', $webAuth);
    $entBody = bodyOf($entitlement);
    $accountV0 = httpRequest('GET', '/api/mobile/v0/account', $webAuth);
    $entV0 = httpRequest('GET', '/api/mobile/v0/entitlement', $webAuth);
    $webUser->refresh();
    $webEnt = $entBody['data'] ?? [];
    $accountEnt = $accountBody['data']['entitlement'] ?? [];
    check(
        'web_entitlement_matches_xboard_user_traffic_and_expiry',
        $account->getStatusCode() === 200
        && $entitlement->getStatusCode() === 200
        && ($accountBody['status'] ?? null) === 'success'
        && ($webEnt['status'] ?? null) === 'active'
        && ($webEnt['connectAllowed'] ?? null) === true
        && ($webEnt['source'] ?? null) === 'web'
        && ($webEnt['playManaged'] ?? null) === false
        && (int) ($webEnt['remainingTrafficBytes'] ?? -1) === $webUser->getRemainingTraffic()
        && (int) ($webEnt['usedTrafficBytes'] ?? -1) === $webUser->getTotalUsedTraffic()
        && (int) ($webEnt['expiresAtEpochMs'] ?? 0) === ((int) $webUser->expired_at * 1000)
        && ($accountEnt['status'] ?? null) === 'active'
        && ($accountBody['data']['opaqueAccountId'] ?? null) === \Plugin\MobileApp\Adapters\AuthAdapter::opaqueAccountId($webUser)
        && str_contains((string) ($accountBody['data']['emailMasked'] ?? ''), '***@'),
        ['http' => $account->getStatusCode(), 'status' => $webEnt['status'] ?? null, 'remaining' => $webEnt['remainingTrafficBytes'] ?? null]
    );
    check(
        'account_and_entitlement_work_on_v0_and_v1',
        $accountV0->getStatusCode() === 200
        && $entV0->getStatusCode() === 200
        && (bodyOf($accountV0)['data']['entitlement']['status'] ?? null) === 'active'
        && !array_key_exists('apiVersion', bodyOf($accountV0))
        && ($accountBody['apiVersion'] ?? null) === 1,
        ['v0' => $accountV0->getStatusCode(), 'v1api' => $accountBody['apiVersion'] ?? null]
    );

    $playUser = makeUser('play-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($playUser, $mapped, 20);
    $playUser->save();
    $secrets[] = (string) $playUser->token;
    \Plugin\MobileApp\Models\EntitlementProjection::query()->create([
        'user_id' => $playUser->id,
        'source' => 'play',
        'purchase_token_id' => null,
        'plan_id' => $mapped->id,
        'expire_at' => time() + 86400 * 20,
        'traffic_bytes' => $playUser->transfer_enable,
        'idempotency_key' => 'play-' . $playUser->id,
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
        'status' => 'active',
        'environment' => 'sandbox',
    ]);
    $playToken = loginToken($playUser->email, $password);
    $playEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($playToken)))['data'] ?? [];
    check(
        'play_entitlement_sets_source_and_play_managed',
        ($playEnt['status'] ?? null) === 'active'
        && ($playEnt['source'] ?? null) === 'play'
        && ($playEnt['playManaged'] ?? null) === true
        && ($playEnt['connectAllowed'] ?? null) === true,
        ['status' => $playEnt['status'] ?? null, 'source' => $playEnt['source'] ?? null]
    );

    $noneUser = makeUser('none-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $noneToken = loginToken($noneUser->email, $password);
    $noneEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($noneToken)))['data'] ?? [];
    check(
        'none_entitlement_is_independent',
        ($noneEnt['status'] ?? null) === 'none'
        && ($noneEnt['connectAllowed'] ?? null) === false
        && ($noneEnt['denialCode'] ?? null) === 'ENTITLEMENT_NONE'
        && ($noneEnt['source'] ?? null) === 'none'
        && array_key_exists('expiresAtEpochMs', $noneEnt)
        && $noneEnt['expiresAtEpochMs'] === null,
        ['status' => $noneEnt['status'] ?? null, 'code' => $noneEnt['denialCode'] ?? null, 'expires' => $noneEnt['expiresAtEpochMs'] ?? null]
    );

    $playOnly = makeUser('playonly-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $playOnly->plan_id = null;
    $playOnly->transfer_enable = 0;
    $playOnly->u = 0;
    $playOnly->d = 0;
    $playOnly->save();
    \Plugin\MobileApp\Models\EntitlementProjection::query()->create([
        'user_id' => $playOnly->id,
        'source' => 'play',
        'plan_id' => $mapped->id,
        'expire_at' => time() + 86400 * 20,
        'traffic_bytes' => 128 * 1073741824,
        'idempotency_key' => 'play-only-' . $playOnly->id,
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
        'status' => 'active',
        'environment' => 'sandbox',
    ]);
    $playOnlyToken = loginToken($playOnly->email, $password);
    $playOnlyEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($playOnlyToken)))['data'] ?? [];
    $playOnly->refresh();
    check(
        'play_projection_cannot_replace_xboard_billing_traffic',
        ($playOnlyEnt['source'] ?? null) === 'play'
        && ($playOnlyEnt['status'] ?? null) === 'exhausted'
        && ($playOnlyEnt['connectAllowed'] ?? null) === false
        && ($playOnlyEnt['denialCode'] ?? null) === 'ENTITLEMENT_EXHAUSTED'
        && (int) ($playOnlyEnt['remainingTrafficBytes'] ?? -1) === $playOnly->getRemainingTraffic()
        && $playOnly->getRemainingTraffic() === 0,
        ['status' => $playOnlyEnt['status'] ?? null, 'remaining' => $playOnlyEnt['remainingTrafficBytes'] ?? null]
    );

    $expiredUser = makeUser('exp-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($expiredUser, $mapped, 10);
    $expiredUser->expired_at = time() - 60;
    $expiredUser->save();
    $expiredToken = loginToken($expiredUser->email, $password);
    $expiredEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($expiredToken)))['data'] ?? [];
    check(
        'expired_entitlement_is_independent',
        ($expiredEnt['status'] ?? null) === 'expired'
        && ($expiredEnt['connectAllowed'] ?? null) === false
        && ($expiredEnt['denialCode'] ?? null) === 'ENTITLEMENT_EXPIRED',
        ['status' => $expiredEnt['status'] ?? null, 'code' => $expiredEnt['denialCode'] ?? null]
    );

    $exhaustedUser = makeUser('exh-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($exhaustedUser, $mapped, 10);
    $exhaustedUser->u = (int) $exhaustedUser->transfer_enable;
    $exhaustedUser->d = 0;
    $exhaustedUser->save();
    $exhaustedToken = loginToken($exhaustedUser->email, $password);
    $exhaustedEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($exhaustedToken)))['data'] ?? [];
    $exhaustedUser->refresh();
    check(
        'exhausted_entitlement_is_independent',
        ($exhaustedEnt['status'] ?? null) === 'exhausted'
        && ($exhaustedEnt['connectAllowed'] ?? null) === false
        && ($exhaustedEnt['denialCode'] ?? null) === 'ENTITLEMENT_EXHAUSTED'
        && (int) ($exhaustedEnt['remainingTrafficBytes'] ?? -1) === $exhaustedUser->getRemainingTraffic()
        && $exhaustedUser->getRemainingTraffic() === 0,
        ['status' => $exhaustedEnt['status'] ?? null, 'remaining' => $exhaustedEnt['remainingTrafficBytes'] ?? null]
    );

    $bannedUser = makeUser('ban-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($bannedUser, $mapped, 10);
    $bannedUser->save();
    $bannedToken = loginToken($bannedUser->email, $password);
    $bannedUser->banned = true;
    $bannedUser->save();
    $bannedEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($bannedToken)))['data'] ?? [];
    check(
        'banned_entitlement_is_independent',
        ($bannedEnt['status'] ?? null) === 'banned'
        && ($bannedEnt['connectAllowed'] ?? null) === false
        && ($bannedEnt['denialCode'] ?? null) === 'AUTH_ACCOUNT_BANNED',
        ['status' => $bannedEnt['status'] ?? null, 'code' => $bannedEnt['denialCode'] ?? null]
    );

    $settings = (new \Plugin\MobileApp\Services\StartupConfigService())->settings();
    $settings->maintenance = true;
    $settings->save();
    $maintEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $webAuth))['data'] ?? [];
    $settings->maintenance = false;
    $settings->save();
    check(
        'maintenance_entitlement_is_independent',
        ($maintEnt['status'] ?? null) === 'maintenance'
        && ($maintEnt['connectAllowed'] ?? null) === false
        && ($maintEnt['denialCode'] ?? null) === 'SERVICE_MAINTENANCE',
        ['status' => $maintEnt['status'] ?? null, 'code' => $maintEnt['denialCode'] ?? null]
    );

    $longUser = makeUser('long-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($longUser, $mapped, 200);
    $longUser->save();
    $webExpiry = (int) $longUser->expired_at;
    \Plugin\MobileApp\Models\EntitlementProjection::query()->create([
        'user_id' => $longUser->id,
        'source' => 'play',
        'plan_id' => $mapped->id,
        'expire_at' => time() + 86400 * 30,
        'traffic_bytes' => $longUser->transfer_enable,
        'idempotency_key' => 'long-' . $longUser->id,
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
        'status' => 'active',
        'environment' => 'sandbox',
    ]);
    $longToken = loginToken($longUser->email, $password);
    $longEnt = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', authHeaders($longToken)))['data'] ?? [];
    $longUser->refresh();
    check(
        'longer_existing_web_expiry_is_not_shortened_by_play',
        ($longEnt['source'] ?? null) === 'play'
        && (int) ($longEnt['expiresAtEpochMs'] ?? 0) === ($webExpiry * 1000)
        && (int) $longUser->expired_at === $webExpiry
        && (int) ($longEnt['expiresAtEpochMs'] ?? 0) > ((time() + 86400 * 30) * 1000),
        ['expires' => $longEnt['expiresAtEpochMs'] ?? null, 'web' => $webExpiry]
    );

    $beforePlan = $webUser->plan_id;
    $beforeExpiry = $webUser->expired_at;
    $beforeTransfer = $webUser->transfer_enable;
    $tamper = httpRequest(
        'GET',
        '/api/mobile/v1/entitlement',
        $webAuth,
        [
            'planId' => $unmapped->id,
            'groupId' => 99,
            'trafficLimit' => 1,
            'expiresAt' => time() + 99999999,
            'plan_id' => $unmapped->id,
            'expired_at' => time() + 99999999,
            'transfer_enable' => 1,
        ],
        ['planId' => $unmapped->id, 'expiresAt' => time() + 99999999, 'trafficLimit' => 1]
    );
    $tamperEnt = bodyOf($tamper)['data'] ?? [];
    $webUser->refresh();
    check(
        'client_plan_group_traffic_expiry_claims_are_ignored',
        $tamper->getStatusCode() === 200
        && (int) ($tamperEnt['remainingTrafficBytes'] ?? -1) === $webUser->getRemainingTraffic()
        && (int) ($tamperEnt['expiresAtEpochMs'] ?? 0) === ((int) $webUser->expired_at * 1000)
        && $webUser->plan_id === $beforePlan
        && $webUser->expired_at == $beforeExpiry
        && $webUser->transfer_enable == $beforeTransfer
        && (int) ($tamperEnt['remainingTrafficBytes'] ?? 0) !== 1,
        ['remaining' => $tamperEnt['remainingTrafficBytes'] ?? null, 'plan' => $webUser->plan_id]
    );

    $plansV1 = httpRequest('GET', '/api/mobile/v1/plans', $webAuth, ['planId' => $unmapped->id, 'stripeUrl' => 'https://example.invalid']);
    $plansV0 = httpRequest('GET', '/api/mobile/v0/plans', $webAuth);
    $planItems = bodyOf($plansV1)['data']['items'] ?? [];
    $names = array_column(is_array($planItems) ? $planItems : [], 'name');
    $encodedPlans = json_encode($planItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $availableNames = (new \App\Services\PlanService(new \App\Models\Plan()))->getAvailablePlans()->pluck('name')->all();
    $productCount = \Plugin\MobileApp\Models\PlayProduct::query()->count();
    check(
        'play_plans_only_return_mapped_products',
        $plansV1->getStatusCode() === 200
        && $plansV0->getStatusCode() === 200
        && in_array('play-mapped', $names, true)
        && !in_array('web-unmapped', $names, true)
        && isset($planItems[0]['playProductId'], $planItems[0]['opaquePlanId']),
        ['names' => $names, 'available' => $availableNames, 'products' => $productCount, 'http' => $plansV1->getStatusCode()]
    );
    check(
        'plans_omit_web_and_wallet_checkout_fields',
        !str_contains($encodedPlans, 'stripeUrl')
        && !str_contains($encodedPlans, 'bepusdt')
        && !str_contains($encodedPlans, 'walletTopup')
        && !str_contains($encodedPlans, 'webCheckout')
        && !isset($planItems[0]['prices'], $planItems[0]['groupId'], $planItems[0]['id']),
        ['keys' => array_keys($planItems[0] ?? [])]
    );

    $accountJson = json_encode($accountBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'account_omits_userid_uuid_token',
        !isset($accountBody['data']['userId'], $accountBody['data']['id'], $accountBody['data']['uuid'], $accountBody['data']['token'], $accountBody['data']['subscriptionToken'], $accountBody['data']['balance'])
        && !str_contains($accountJson, (string) $webUser->uuid)
        && !str_contains($accountJson, (string) $webUser->token),
        ['keys' => array_keys($accountBody['data'] ?? [])]
    );

    $encoded = json_encode($tests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $leaked = false;
    foreach ($secrets as $secret) {
        if ($secret !== '' && (str_contains($encoded, $secret) || str_contains($sink, $secret))) {
            $leaked = true;
            break;
        }
    }
    check(
        'responses_logs_and_report_omit_plaintext_tokens',
        !$leaked && $sink !== '',
        ['leaked' => $leaked]
    );
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'line' => $exception->getLine()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-023',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
