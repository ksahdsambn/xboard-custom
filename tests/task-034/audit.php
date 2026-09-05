<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task034.sqlite',
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

function enableAutoRenew(\App\Models\User $user): void
{
    $row = \Plugin\WalletCenter\Models\AutoRenewSetting::query()->firstOrNew(['user_id' => $user->id]);
    $row->enabled = true;
    $row->next_scan_at = now()->subMinute();
    $row->save();
}

function lastRecord(int $userId): ?\Plugin\WalletCenter\Models\AutoRenewRecord
{
    return \Plugin\WalletCenter\Models\AutoRenewRecord::query()->where('user_id', $userId)->orderByDesc('id')->first();
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
    $password = 'task034-pass';
    $plan = makePlan('play-mapped');
    $webPlan = makePlan('web-plan');
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

    $auto = new \Plugin\WalletCenter\Services\AutoRenewService(
        new \Plugin\WalletCenter\Services\WalletCenterConfigService(),
        new \App\Services\UserService(),
        new \Plugin\WalletCenter\Services\WalletCenterNotificationService()
    );
    $entitlement = new \Plugin\MobileApp\Services\EntitlementService(new \App\Services\UserService());

    $cases = [
        ['tok-purchased', 'play_managed_entitlement', true],
        ['tok-canceled', 'play_managed_entitlement', true],
        ['tok-grace', 'play_managed_entitlement', true],
        ['tok-hold', 'play_account_hold', true],
        ['tok-expired', null, false],
    ];
    $results = [];
    foreach ($cases as [$token, $expectedReason, $blocked]) {
        $user = makeUser($token . '-' . bin2hex(random_bytes(3)) . '@example.invalid', $password);
        $user->balance = 9999;
        $user->expired_at = time() + 86400;
        $user->save();
        $auth = authHeaders(loginToken($user->email, $password));
        httpRequest('POST', '/api/mobile/v1/play/purchases', $auth, [
            'productId' => 'dev.xboard.sub.monthly',
            'purchaseToken' => $token,
            'obfuscatedAccountId' => 'obf-' . bin2hex(random_bytes(4)),
        ]);
        $user->refresh();
        $beforeBalance = (int) $user->balance;
        $beforeExpiry = (int) ($user->expired_at ?? 0);
        $reason = $entitlement->walletAutoRenewBlockReason($user);
        enableAutoRenew($user);
        $auto->scan(20, false);
        $user->refresh();
        $record = lastRecord((int) $user->id);
        $results[$token] = [
            'reason' => $reason,
            'recordReason' => $record?->reason,
            'status' => $record ? (int) $record->status : null,
            'balance' => (int) $user->balance,
            'expiry' => (int) ($user->expired_at ?? 0),
        ];
        $ok = $reason === $expectedReason
            && (int) $user->balance === $beforeBalance
            && (int) ($user->expired_at ?? 0) === $beforeExpiry;
        if ($blocked) {
            $ok = $ok && $record instanceof \Plugin\WalletCenter\Models\AutoRenewRecord
                && (int) $record->status === \Plugin\WalletCenter\Models\AutoRenewRecord::STATUS_SKIPPED
                && $record->reason === $expectedReason;
        } else {
            $ok = $ok && ($record === null || !in_array($record->reason, ['play_managed_entitlement', 'play_account_hold'], true));
        }
        check('wallet_scan_' . str_replace('tok-', '', $token), $ok, $results[$token]);
    }

    $web = makeUser('web-' . bin2hex(random_bytes(3)) . '@example.invalid', $password);
    $web->plan_id = $webPlan->id;
    $web->group_id = $webPlan->group_id;
    $web->expired_at = time() + 86400;
    $web->balance = 8888;
    $web->save();
    $webReason = $entitlement->walletAutoRenewBlockReason($web);
    enableAutoRenew($web);
    $beforeWeb = (int) $web->balance;
    $auto->scan(20, false);
    $web->refresh();
    $webRecord = lastRecord((int) $web->id);
    check(
        'web_only_not_play_blocked',
        $webReason === null
        && (int) $web->balance === $beforeWeb
        && ($webRecord === null || !in_array($webRecord->reason, ['play_managed_entitlement', 'play_account_hold'], true)),
        ['reason' => $webReason, 'record' => $webRecord?->reason]
    );

    $enableUser = makeUser('enable-' . bin2hex(random_bytes(3)) . '@example.invalid', $password);
    $enableAuth = authHeaders(loginToken($enableUser->email, $password));
    httpRequest('POST', '/api/mobile/v1/play/purchases', $enableAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-restore',
        'obfuscatedAccountId' => 'obf-' . bin2hex(random_bytes(4)),
    ]);
    $enableUser->refresh();
    $threw = false;
    try {
        $auto->updateSetting($enableUser, true);
    } catch (\Throwable $exception) {
        $threw = str_contains($exception->getMessage(), 'Google Play') || str_contains($exception->getMessage(), '自动续费');
    }
    check('user_cannot_enable_auto_renew_for_play', $threw, ['threw' => $threw]);

    $plans = bodyOf(httpRequest('GET', '/api/mobile/v1/plans', $adminAuth));
    $encoded = json_encode($plans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'play_catalog_omits_wallet_and_external_checkout',
        !str_contains($encoded, 'walletTopup')
        && !str_contains($encoded, 'stripeUrl')
        && !str_contains($encoded, 'bepusdt')
        && !str_contains($encoded, 'webCheckout'),
        ['hasItems' => isset($plans['data']['items'])]
    );

    $ent = bodyOf(httpRequest('GET', '/api/mobile/v1/entitlement', $enableAuth));
    check(
        'entitlement_exposes_play_managed_block',
        ($ent['data']['playManaged'] ?? null) === true
        && ($ent['data']['walletAutoRenewBlocked'] ?? null) === true
        && ($ent['data']['walletAutoRenewBlockReason'] ?? null) === 'play_managed_entitlement',
        ['source' => $ent['data']['source'] ?? null, 'reason' => $ent['data']['walletAutoRenewBlockReason'] ?? null]
    );

    echo json_encode([
        'taskId' => 'TASK-034',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-034',
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
