<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task030.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'auth_data', 'subscriptiontoken'], true)) {
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

function makeUser(string $email, string $password, bool $admin = false): \App\Models\User
{
    $user = (new \App\Services\UserService())->createUser(['email' => $email, 'password' => $password]);
    $user->is_admin = $admin;
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

    $password = 'task030-pass';
    $mapped = makePlan('play-mapped');
    $hidden = makePlan('hidden-plan', false, true);
    $unsold = makePlan('unsold-plan', true, false);
    $other = makePlan('other-sellable');

    $admin = makeUser('admin-' . bin2hex(random_bytes(4)) . '@example.invalid', $password, true);
    $user = makeUser('user-' . bin2hex(random_bytes(4)) . '@example.invalid', $password, false);
    $adminToken = loginToken($admin->email, $password);
    $userToken = loginToken($user->email, $password);
    $adminAuth = authHeaders($adminToken);
    $userAuth = authHeaders($userToken);

    $service = new \Plugin\MobileApp\Services\PlayProductService(new \App\Services\PlanService(new \App\Models\Plan()));
    $create = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $mapped->id,
        'enabled' => true,
        'price' => 99,
        'planId' => $other->id,
        'duration' => 365,
        'expiresAt' => time() + 999999,
    ]);
    $createBody = bodyOf($create);
    $createV0 = httpRequest('PUT', '/api/mobile/v0/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $mapped->id,
        'enabled' => true,
    ]);
    $row = \Plugin\MobileApp\Models\PlayProduct::query()->first();
    check(
        'correct_mapping_creates_sandbox_product',
        $create->getStatusCode() === 200
        && ($createBody['status'] ?? null) === 'success'
        && ($createBody['data']['enabled'] ?? null) === true
        && $createV0->getStatusCode() === 200
        && $row instanceof \Plugin\MobileApp\Models\PlayProduct
        && (int) $row->xboard_plan_id === (int) $mapped->id
        && $row->product_id === 'dev.xboard.sub.monthly',
        ['http' => $create->getStatusCode(), 'plan' => $row->xboard_plan_id ?? null]
    );

    $dup = null;
    $dupCode = null;
    try {
        $service->create([
            'packageName' => 'dev.xboard.xboard_mobile',
            'productId' => 'dev.xboard.sub.monthly',
            'basePlanId' => 'p1y',
            'environment' => 'sandbox',
            'xboardPlanId' => $other->id,
        ]);
    } catch (\Plugin\MobileApp\Exceptions\MobileApiException $exception) {
        $dup = $exception;
        $dupCode = $exception->errorCode;
    }
    check(
        'duplicate_product_id_rejected',
        $dupCode === 'PLAY_PRODUCT_DUPLICATE'
        && \Plugin\MobileApp\Models\PlayProduct::query()->count() === 1,
        ['code' => $dupCode, 'count' => \Plugin\MobileApp\Models\PlayProduct::query()->count()]
    );

    $wrongPkg = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'com.other.app',
        'productId' => 'dev.xboard.sub.other',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $mapped->id,
    ]);
    check(
        'wrong_package_rejected',
        $wrongPkg->getStatusCode() === 400
        && (bodyOf($wrongPkg)['errorCode'] ?? null) === 'PLAY_PRODUCT_INVALID'
        && \Plugin\MobileApp\Models\PlayProduct::query()->where('package_name', 'com.other.app')->doesntExist(),
        ['http' => $wrongPkg->getStatusCode(), 'code' => bodyOf($wrongPkg)['errorCode'] ?? null]
    );

    $prodMap = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.prod',
        'basePlanId' => 'p1m',
        'environment' => 'production',
        'xboardPlanId' => $mapped->id,
        'enabled' => true,
    ]);
    $plansSandbox = httpRequest('GET', '/api/mobile/v1/plans', $userAuth, ['price' => 1, 'planId' => $other->id, 'duration' => 12]);
    $plansV0 = httpRequest('GET', '/api/mobile/v0/plans', $userAuth);
    $planItems = bodyOf($plansSandbox)['data']['items'] ?? [];
    $productIds = array_column(is_array($planItems) ? $planItems : [], 'playProductId');
    check(
        'production_mapping_does_not_appear_in_sandbox_catalog',
        $prodMap->getStatusCode() === 200
        && $plansSandbox->getStatusCode() === 200
        && $plansV0->getStatusCode() === 200
        && in_array('dev.xboard.sub.monthly', $productIds, true)
        && !in_array('dev.xboard.sub.prod', $productIds, true),
        ['ids' => $productIds]
    );

    $disable = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $mapped->id,
        'enabled' => false,
    ]);
    $disabledItems = bodyOf(httpRequest('GET', '/api/mobile/v1/plans', $userAuth))['data']['items'] ?? [];
    $adminList = bodyOf(httpRequest('GET', '/api/mobile/v1/admin/play-products', $adminAuth))['data']['items'] ?? [];
    $adminIds = array_column(is_array($adminList) ? $adminList : [], 'productId');
    check(
        'disabled_product_excluded_from_sellable_but_listed_for_admin',
        $disable->getStatusCode() === 200
        && ($disableBodyEnabled = (bodyOf($disable)['data']['enabled'] ?? true)) === false
        && $disabledItems === []
        && in_array('dev.xboard.sub.monthly', $adminIds, true),
        ['enabled' => $disableBodyEnabled, 'sellable' => $disabledItems, 'admin' => $adminIds]
    );

    httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $mapped->id,
        'enabled' => true,
    ]);

    $invalidHidden = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.hidden',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $hidden->id,
    ]);
    $invalidUnsold = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.unsold',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $unsold->id,
    ]);
    check(
        'invalid_or_unsellable_plan_rejected',
        (bodyOf($invalidHidden)['errorCode'] ?? null) === 'PLAY_PRODUCT_INVALID'
        && (bodyOf($invalidUnsold)['errorCode'] ?? null) === 'PLAY_PRODUCT_INVALID',
        ['hidden' => bodyOf($invalidHidden)['errorCode'] ?? null, 'unsold' => bodyOf($invalidUnsold)['errorCode'] ?? null]
    );
    $disableHidden = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.hidden',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $hidden->id,
        'enabled' => false,
    ]);
    check(
        'disabled_mapping_can_keep_unsellable_plan',
        $disableHidden->getStatusCode() === 200
        && (bodyOf($disableHidden)['data']['enabled'] ?? true) === false,
        ['http' => $disableHidden->getStatusCode(), 'code' => bodyOf($disableHidden)['errorCode'] ?? null]
    );

    $user->refresh();
    $beforePlan = $user->plan_id;
    $claimOnly = httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.claim',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'planId' => $other->id,
        'price' => 1,
        'duration' => 12,
        'expiresAt' => time() + 99999,
    ]);
    $entitlement = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $userAuth))['data'] ?? [];
    $user->refresh();
    $encodedPlans = json_encode(bodyOf(httpRequest('GET', '/api/mobile/v1/plans', $userAuth))['data']['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'client_price_plan_duration_ignored_and_mapping_does_not_grant_entitlement',
        (bodyOf($claimOnly)['errorCode'] ?? null) === 'PLAY_PRODUCT_INVALID'
        && $user->plan_id === $beforePlan
        && ($entitlement['status'] ?? null) === 'none'
        && ($entitlement['connectAllowed'] ?? null) === false
        && !str_contains($encodedPlans, 'stripeUrl')
        && !str_contains($encodedPlans, 'bepusdt')
        && !str_contains($encodedPlans, 'walletTopup')
        && !str_contains($encodedPlans, 'webCheckout'),
        ['claim' => bodyOf($claimOnly)['errorCode'] ?? null, 'status' => $entitlement['status'] ?? null]
    );

    $forbidden = httpRequest('GET', '/api/mobile/v1/admin/play-products', $userAuth);
    $purchase = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, ['productId' => 'dev.xboard.sub.monthly', 'purchaseToken' => 'ignored']);
    $purchaseCode = (string) (bodyOf($purchase)['errorCode'] ?? '');
    $audits = \Plugin\MobileApp\Models\PlayProductAudit::query()->pluck('action')->all();
    $v1List = httpRequest('GET', '/api/mobile/v1/admin/play-products', $adminAuth);
    $v0List = httpRequest('GET', '/api/mobile/v0/admin/play-products', $adminAuth);
    check(
        'admin_auth_audit_compat_and_purchase_not_granted_by_mapping',
        (bodyOf($forbidden)['errorCode'] ?? null) === 'AUTH_FORBIDDEN'
        && in_array($purchaseCode, ['OPERATION_NOT_IMPLEMENTED', 'PURCHASE_INVALID', 'PURCHASE_PENDING'], true)
        && in_array('create', $audits, true)
        && in_array('disable', $audits, true)
        && $v1List->getStatusCode() === 200
        && $v0List->getStatusCode() === 200
        && (bodyOf($v1List)['apiVersion'] ?? null) === 1
        && !array_key_exists('apiVersion', bodyOf($v0List)),
        ['forbidden' => bodyOf($forbidden)['errorCode'] ?? null, 'purchase' => bodyOf($purchase)['errorCode'] ?? null, 'audits' => $audits]
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
    check('responses_and_logs_omit_secrets', !$leaked, ['leaked' => $leaked]);

    echo json_encode([
        'taskId' => 'TASK-030',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-030',
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
