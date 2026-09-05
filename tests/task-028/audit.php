<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task028.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization'], true)) {
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

function devicePayload(string $opaque, array $override = []): array
{
    return array_merge([
        'opaqueDeviceId' => $opaque,
        'platform' => 'android',
        'appVersion' => '1.0.0',
        'androidApi' => 26,
        'mobileApiVersion' => 1,
        'profileSchemaVersion' => 1,
        'libxrayVersion' => '15e88365296a6f955e5e38caa2d02c97b499733f',
        'xrayCoreVersion' => 'v26.7.28',
    ], $override);
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

    $password = 'task028-pass';
    $userA = makeUser('a-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $userB = makeUser('b-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $authA = authHeaders(loginToken($userA->email, $password));

    $before = httpRequest('GET', '/api/mobile/v1/entitlement', $authA);
    $first = httpRequest('PUT', '/api/mobile/v1/devices', $authA, devicePayload('device-alpha-01'));
    $repeat = httpRequest('PUT', '/api/mobile/v1/devices', $authA, devicePayload('device-alpha-01', ['appVersion' => '1.1.0', 'androidApi' => 37]));
    $secondDevice = httpRequest('PUT', '/api/mobile/v1/devices', $authA, devicePayload('device-beta-02'));
    $after = httpRequest('GET', '/api/mobile/v1/entitlement', $authA);
    check(
        'first_repeat_upgrade_and_multi_device',
        $first->getStatusCode() === 200 && $repeat->getStatusCode() === 200 && $secondDevice->getStatusCode() === 200
        && (bodyOf($first)['data']['opaqueDeviceId'] ?? null) === 'device-alpha-01'
        && (bodyOf($repeat)['data']['opaqueDeviceId'] ?? null) === 'device-alpha-01'
        && \Plugin\MobileApp\Models\Device::query()->where('user_id', $userA->id)->count() === 2
        && \Plugin\MobileApp\Models\Device::query()->where('opaque_device_id', 'device-alpha-01')->value('app_version') === '1.1.0',
        ['count' => \Plugin\MobileApp\Models\Device::query()->where('user_id', $userA->id)->count()]
    );
    check(
        'registration_does_not_change_entitlement',
        (bodyOf($before)['data']['status'] ?? null) === (bodyOf($after)['data']['status'] ?? 'x')
        && (bodyOf($before)['data']['connectAllowed'] ?? null) === (bodyOf($after)['data']['connectAllowed'] ?? 'x'),
        ['before' => bodyOf($before)['data']['status'] ?? null]
    );

    $logout = httpRequest('POST', '/api/mobile/v1/auth/logout', $authA);
    $authA2 = authHeaders(loginToken($userA->email, $password));
    $again = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('device-alpha-01', ['appVersion' => '1.1.1']));
    check(
        'logout_relogin_reuses_same_device_row',
        $logout->getStatusCode() === 200 && $again->getStatusCode() === 200
        && \Plugin\MobileApp\Models\Device::query()->where('user_id', $userA->id)->where('opaque_device_id', 'device-alpha-01')->count() === 1,
        ['rows' => \Plugin\MobileApp\Models\Device::query()->where('user_id', $userA->id)->count()]
    );

    $ads = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('device-gamma-03', ['advertisingId' => 'gaid-value']));
    $imei = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('device-gamma-03', ['imei' => '123']));
    $badId = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('x'));
    check(
        'advertising_and_hardware_ids_rejected',
        $ads->getStatusCode() === 400 && (bodyOf($ads)['errorCode'] ?? null) === 'DEVICE_INVALID'
        && $imei->getStatusCode() === 400 && (bodyOf($imei)['errorCode'] ?? null) === 'DEVICE_INVALID'
        && $badId->getStatusCode() === 400,
        ['ads' => bodyOf($ads)['errorCode'] ?? null]
    );

    $authB = authHeaders(loginToken($userB->email, $password));
    $other = httpRequest('PUT', '/api/mobile/v1/devices', $authB, devicePayload('device-alpha-01'));
    check(
        'same_opaque_id_isolated_per_user',
        $other->getStatusCode() === 200
        && \Plugin\MobileApp\Models\Device::query()->where('opaque_device_id', 'device-alpha-01')->count() === 2,
        ['owners' => \Plugin\MobileApp\Models\Device::query()->where('opaque_device_id', 'device-alpha-01')->count()]
    );

    $tamper = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('device-alpha-01', [
        'appVersion' => '99.0.0',
        'libxrayVersion' => 'forged',
        'planId' => 999,
        'connectable' => true,
    ]));
    $ent = httpRequest('GET', '/api/mobile/v1/entitlement', $authA2);
    check(
        'forged_versions_do_not_grant_entitlement',
        $tamper->getStatusCode() === 200
        && (bodyOf($ent)['data']['connectAllowed'] ?? true) === false
        && (bodyOf($ent)['data']['status'] ?? '') === (bodyOf($before)['data']['status'] ?? 'none'),
        ['connectAllowed' => bodyOf($ent)['data']['connectAllowed'] ?? null]
    );

    $v0 = httpRequest('PUT', '/api/mobile/v0/devices', $authA2, devicePayload('device-v0-04'));
    $v1 = httpRequest('PUT', '/api/mobile/v1/devices', $authA2, devicePayload('device-v1-05'));
    $blob = json_encode([bodyOf($first), bodyOf($repeat), bodyOf($tamper)], JSON_UNESCAPED_UNICODE) ?: '';
    check(
        'v0_v1_register_and_no_ads_fields_in_response',
        $v0->getStatusCode() === 200 && $v1->getStatusCode() === 200
        && !array_key_exists('apiVersion', bodyOf($v0))
        && (bodyOf($v1)['apiVersion'] ?? null) === 1
        && !str_contains($blob, 'advertisingId')
        && !str_contains($blob, 'imei')
        && !str_contains($blob, 'hardwareSerial'),
        ['v0' => $v0->getStatusCode(), 'v1' => $v1->getStatusCode()]
    );
} catch (\Throwable $e) {
    check('runtime_exception', false, ['class' => $e::class, 'message' => $e->getMessage()]);
}

$failed = array_values(array_filter($tests, static fn(array $item): bool => !$item['passed']));
echo json_encode([
    'taskId' => 'TASK-028',
    'status' => $failed ? 'failed' : 'passed',
    'formalAcceptanceClaimed' => false,
    'deviceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit($failed ? 1 : 0);
