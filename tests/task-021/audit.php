<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task021.sqlite',
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

function httpRequest(string $method, string $path, array $headers = [], ?array $json = null): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $key => $value) {
        $server[$key] = $value;
    }
    $content = null;
    if ($json !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        $content = json_encode($json);
    }
    $request = \Illuminate\Http\Request::create($path, $method, [], [], [], $server, $content);
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

function adapterCall(callable $fn): array
{
    try {
        return ['ok' => true, 'data' => $fn(), 'code' => null];
    } catch (\Plugin\MobileApp\Exceptions\MobileApiException $exception) {
        return ['ok' => false, 'data' => null, 'code' => $exception->errorCode, 'http' => $exception->httpStatus()];
    }
}

function makeUser(string $email, string $password, bool $banned = false): \App\Models\User
{
    $service = new \App\Services\UserService();
    $user = $service->createUser(['email' => $email, 'password' => $password]);
    $user->banned = $banned;
    $user->save();
    return $user;
}

try {
    $app = require '/audit/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

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
        'password_limit_enable' => 1,
        'password_limit_count' => 5,
        'password_limit_expire' => 60,
        'register_limit_by_ip_enable' => 0,
    ]);

    $adapter = app(\Plugin\MobileApp\Adapters\AuthAdapter::class);
    $loginService = app(\App\Services\Auth\LoginService::class);
    $registerService = app(\App\Services\Auth\RegisterService::class);
    $password = 'task021-pass';

    $user = makeUser('login-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $officialLogin = httpRequest('POST', '/api/v1/passport/auth/login', [], ['email' => $user->email, 'password' => $password]);
    $officialLoginBody = bodyOf($officialLogin);
    $mobileLogin = adapterCall(fn () => $adapter->login($user->email, $password));
    $session = $mobileLogin['data'] ?? [];
    check(
        'official_and_adapter_login_eligibility_match_success',
        $officialLogin->getStatusCode() === 200
        && ($officialLoginBody['data']['token'] ?? '') !== ''
        && ($officialLoginBody['data']['auth_data'] ?? '') !== ''
        && ($mobileLogin['ok'] ?? false) === true
        && ($session['tokenType'] ?? null) === 'Bearer'
        && isset($session['sanctumToken'], $session['expiresAtEpochMs'])
        && !isset($session['token'], $session['auth_data'], $session['subscriptionToken'], $session['is_admin']),
        ['officialHttp' => $officialLogin->getStatusCode(), 'mobileOk' => $mobileLogin['ok'] ?? false]
    );

    $wrongOfficial = $loginService->login($user->email, 'wrong-password');
    $wrongMobile = adapterCall(fn () => $adapter->login($user->email, 'wrong-password'));
    check(
        'wrong_password_eligibility_matches_and_maps_credentials',
        $wrongOfficial[0] === false
        && $wrongOfficial[1][0] === 400
        && ($wrongMobile['ok'] ?? true) === false
        && ($wrongMobile['code'] ?? null) === 'AUTH_CREDENTIALS_INVALID',
        ['code' => $wrongMobile['code'] ?? null]
    );

    \Illuminate\Support\Facades\Cache::forget(\App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $user->email));
    admin_setting(['password_limit_count' => 2]);
    $loginService->login($user->email, 'wrong-password');
    $loginService->login($user->email, 'wrong-password');
    $limitedOfficial = $loginService->login($user->email, $password);
    $limitedMobile = adapterCall(fn () => $adapter->login($user->email, $password));
    check(
        'password_error_limit_maps_to_auth_rate_limited',
        $limitedOfficial[0] === false
        && $limitedOfficial[1][0] === 429
        && ($limitedMobile['ok'] ?? true) === false
        && ($limitedMobile['code'] ?? null) === 'AUTH_RATE_LIMITED',
        ['code' => $limitedMobile['code'] ?? null]
    );
    \Illuminate\Support\Facades\Cache::forget(\App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $user->email));
    admin_setting(['password_limit_count' => 5]);

    $banned = makeUser('banned-' . bin2hex(random_bytes(4)) . '@example.invalid', $password, true);
    $bannedOfficial = $loginService->login($banned->email, $password);
    $bannedMobile = adapterCall(fn () => $adapter->login($banned->email, $password));
    check(
        'banned_account_maps_to_auth_account_banned',
        $bannedOfficial[0] === false
        && ($bannedMobile['ok'] ?? true) === false
        && ($bannedMobile['code'] ?? null) === 'AUTH_ACCOUNT_BANNED'
        && ($bannedMobile['http'] ?? null) === 403,
        ['code' => $bannedMobile['code'] ?? null]
    );

    admin_setting(['stop_register' => 1]);
    $closedReq = \Illuminate\Http\Request::create('/', 'POST', [
        'email' => 'closed-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => $password,
    ]);
    $closedOfficial = $registerService->validateRegister($closedReq);
    $closedMobile = adapterCall(fn () => $adapter->register($closedReq));
    check(
        'stop_register_maps_to_auth_register_disabled',
        $closedOfficial[0] === false
        && ($closedMobile['ok'] ?? true) === false
        && ($closedMobile['code'] ?? null) === 'AUTH_REGISTER_DISABLED',
        ['code' => $closedMobile['code'] ?? null]
    );
    admin_setting(['stop_register' => 0]);

    admin_setting(['email_whitelist_enable' => 1, 'email_whitelist_suffix' => ['allowed.invalid']]);
    $whiteReq = \Illuminate\Http\Request::create('/', 'POST', [
        'email' => 'deny-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => $password,
    ]);
    $whiteOfficial = $registerService->validateRegister($whiteReq);
    $whiteMobile = adapterCall(fn () => $adapter->register($whiteReq));
    check(
        'email_whitelist_maps_to_auth_email_restricted',
        $whiteOfficial[0] === false
        && ($whiteMobile['ok'] ?? true) === false
        && ($whiteMobile['code'] ?? null) === 'AUTH_EMAIL_RESTRICTED',
        ['code' => $whiteMobile['code'] ?? null]
    );
    admin_setting(['email_whitelist_enable' => 0]);

    admin_setting(['invite_force' => 1]);
    $inviteReq = \Illuminate\Http\Request::create('/', 'POST', [
        'email' => 'invite-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => $password,
    ]);
    $inviteOfficial = $registerService->validateRegister($inviteReq);
    $inviteMobile = adapterCall(fn () => $adapter->register($inviteReq));
    check(
        'missing_invite_maps_to_auth_invite_required',
        $inviteOfficial[0] === false
        && $inviteOfficial[1][0] === 422
        && ($inviteMobile['ok'] ?? true) === false
        && ($inviteMobile['code'] ?? null) === 'AUTH_INVITE_REQUIRED',
        ['code' => $inviteMobile['code'] ?? null]
    );
    admin_setting(['invite_force' => 0]);

    admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);
    $captchaReq = \Illuminate\Http\Request::create('/', 'POST', [
        'email' => 'captcha-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => $password,
    ]);
    $captchaOfficial = $registerService->validateRegister($captchaReq);
    $captchaMobile = adapterCall(fn () => $adapter->register($captchaReq));
    check(
        'captcha_failure_maps_to_auth_captcha_failed',
        $captchaOfficial[0] === false
        && ($captchaMobile['ok'] ?? true) === false
        && ($captchaMobile['code'] ?? null) === 'AUTH_CAPTCHA_FAILED',
        ['code' => $captchaMobile['code'] ?? null]
    );
    admin_setting(['captcha_enable' => 0]);

    $existsEmail = 'exists-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $existsReq = \Illuminate\Http\Request::create('/', 'POST', ['email' => $existsEmail, 'password' => $password]);
    $created = $adapter->register($existsReq);
    $dupOfficial = $registerService->validateRegister($existsReq);
    $dupMobile = adapterCall(fn () => $adapter->register($existsReq));
    $createdUser = \App\Models\User::byEmail($existsEmail)->first();
    check(
        'register_returns_opaque_id_and_duplicate_maps_email_exists',
        isset($created['opaqueAccountId'])
        && $createdUser instanceof \App\Models\User
        && $created['opaqueAccountId'] === \Plugin\MobileApp\Adapters\AuthAdapter::opaqueAccountId($createdUser)
        && $created['opaqueAccountId'] !== (string) $createdUser->id
        && $created['opaqueAccountId'] !== (string) $createdUser->uuid
        && $dupOfficial[0] === false
        && ($dupMobile['code'] ?? null) === 'AUTH_EMAIL_EXISTS',
        ['dup' => $dupMobile['code'] ?? null]
    );

    $fresh = makeUser('session-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $issued = $adapter->login($fresh->email, $password);
    $bearer = httpRequest('GET', '/api/mobile/v1/account', ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued['sanctumToken']]);
    $subscription = httpRequest('GET', '/api/mobile/v1/account', ['HTTP_AUTHORIZATION' => 'Bearer ' . $fresh->token]);
    $bearerBody = bodyOf($bearer);
    $subscriptionBody = bodyOf($subscription);
    check(
        'bearer_session_reaches_protected_route_subscription_token_rejected',
        $bearer->getStatusCode() === 501
        && ($bearerBody['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
        && $subscription->getStatusCode() === 403
        && ($subscriptionBody['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($subscriptionBody['status'] ?? null) === 'fail',
        ['bearerHttp' => $bearer->getStatusCode(), 'subHttp' => $subscription->getStatusCode(), 'subCode' => $subscriptionBody['errorCode'] ?? null]
    );

    $fresh->tokens()->update(['expires_at' => now()->subMinute()]);
    $expired = httpRequest('GET', '/api/mobile/v1/account', ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued['sanctumToken']]);
    check(
        'expired_sanctum_is_rejected_by_guard_not_manual_lookup',
        $expired->getStatusCode() === 403
        && (bodyOf($expired)['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && \App\Services\AuthService::findUserByBearerToken('Bearer ' . $issued['sanctumToken']) !== null,
        ['expiredHttp' => $expired->getStatusCode()]
    );

    $resetUser = makeUser('reset-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $beforeReset = $adapter->login($resetUser->email, $password);
    $tokenCountBefore = $resetUser->tokens()->count();
    $wrongResetOfficial = $loginService->resetPassword($resetUser->email, '000000', $password);
    $wrongResetMobile = adapterCall(fn () => $adapter->resetPassword($resetUser->email, '000000', $password));
    \Illuminate\Support\Facades\Cache::put(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $resetUser->email), '123456', 300);
    $newPassword = 'task021-new1';
    $resetMobile = adapterCall(fn () => $adapter->resetPassword($resetUser->email, '123456', $newPassword));
    $resetUser->refresh();
    check(
        'reset_eligibility_matches_and_adapter_revokes_sessions',
        $wrongResetOfficial[0] === false
        && ($wrongResetMobile['code'] ?? null) === 'AUTH_EMAIL_CODE_INVALID'
        && ($resetMobile['ok'] ?? false) === true
        && ($resetMobile['data']['accepted'] ?? false) === true
        && $tokenCountBefore >= 1
        && $resetUser->tokens()->count() === 0
        && password_verify($newPassword, $resetUser->password),
        ['wrongCode' => $wrongResetMobile['code'] ?? null, 'before' => $tokenCountBefore, 'after' => $resetUser->tokens()->count()]
    );

    $localeUser = makeUser('locale-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    app()->setLocale('en-US');
    $en = adapterCall(fn () => $adapter->login($localeUser->email, 'wrong-password'));
    app()->setLocale('zh-CN');
    $zh = adapterCall(fn () => $adapter->login($localeUser->email, 'wrong-password'));
    check(
        'machine_code_stable_across_en_and_zh',
        ($en['code'] ?? null) === 'AUTH_CREDENTIALS_INVALID'
        && ($zh['code'] ?? null) === 'AUTH_CREDENTIALS_INVALID',
        ['en' => $en['code'] ?? null, 'zh' => $zh['code'] ?? null]
    );

    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $leaked = str_contains($sink, (string) ($issued['sanctumToken'] ?? 'missing'))
        || str_contains($sink, (string) $fresh->token)
        || str_contains($sink, (string) ($beforeReset['sanctumToken'] ?? 'missing'));
    check(
        'logs_do_not_contain_sanctum_or_subscription_tokens',
        $sink !== '' && !$leaked,
        ['logged' => true]
    );

    $gmailReq = \Illuminate\Http\Request::create('/', 'POST', [
        'email' => 'foo.bar+' . bin2hex(random_bytes(2)) . '@gmail.com',
        'password' => $password,
    ]);
    admin_setting(['email_gmail_limit_enable' => 1]);
    $gmailOfficial = $registerService->validateRegister($gmailReq);
    $gmailMobile = adapterCall(fn () => $adapter->register($gmailReq));
    admin_setting(['email_gmail_limit_enable' => 0]);
    check(
        'gmail_alias_restriction_maps_to_auth_email_restricted',
        $gmailOfficial[0] === false
        && ($gmailMobile['code'] ?? null) === 'AUTH_EMAIL_RESTRICTED',
        ['code' => $gmailMobile['code'] ?? null]
    );

    $forgetEmail = 'forget-' . bin2hex(random_bytes(4)) . '@example.invalid';
    makeUser($forgetEmail, $password);
    $loginService->resetPassword($forgetEmail, '000000', $password);
    $loginService->resetPassword($forgetEmail, '000000', $password);
    $loginService->resetPassword($forgetEmail, '000000', $password);
    $forgetOfficial = $loginService->resetPassword($forgetEmail, '000000', $password);
    $forgetMobile = adapterCall(fn () => $adapter->resetPassword($forgetEmail, '000000', $password));
    check(
        'reset_frequency_limit_maps_to_auth_rate_limited',
        $forgetOfficial[0] === false
        && $forgetOfficial[1][0] === 429
        && ($forgetMobile['code'] ?? null) === 'AUTH_RATE_LIMITED',
        ['code' => $forgetMobile['code'] ?? null]
    );
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'line' => $exception->getLine()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-021',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
