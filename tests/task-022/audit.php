<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task022.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'auth_data', 'subscriptiontoken', 'uuid', 'code'], true)) {
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
        'password_limit_enable' => 1,
        'password_limit_count' => 5,
        'register_limit_by_ip_enable' => 0,
    ]);

    $password = 'task022-pass';
    $email = 'flow-' . bin2hex(random_bytes(4)) . '@example.invalid';

    $reg = httpRequest('POST', '/api/mobile/v1/auth/register', [], ['email' => $email, 'password' => $password]);
    $regBody = bodyOf($reg);
    $regV0 = httpRequest('POST', '/api/mobile/v0/auth/register', [], [
        'email' => 'v0-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => $password,
    ]);
    check(
        'register_v1_and_v0_succeed_without_session_token',
        $reg->getStatusCode() === 200
        && ($regBody['status'] ?? null) === 'success'
        && isset($regBody['data']['opaqueAccountId'])
        && ($regBody['apiVersion'] ?? null) === 1
        && !isset($regBody['data']['sanctumToken'], $regBody['data']['token'], $regBody['data']['subscriptionToken'])
        && $regV0->getStatusCode() === 200
        && !array_key_exists('apiVersion', bodyOf($regV0)),
        ['regHttp' => $reg->getStatusCode(), 'v0' => $regV0->getStatusCode()]
    );

    $dup = httpRequest('POST', '/api/mobile/v1/auth/register', [], ['email' => $email, 'password' => $password]);
    check(
        'duplicate_email_maps_auth_email_exists',
        (bodyOf($dup)['errorCode'] ?? null) === 'AUTH_EMAIL_EXISTS',
        ['code' => bodyOf($dup)['errorCode'] ?? null]
    );

    $login = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $password]);
    $loginBody = bodyOf($login);
    $token = (string) ($loginBody['data']['sanctumToken'] ?? '');
    $secrets[] = $token;
    $user = \App\Models\User::byEmail($email)->first();
    if ($user) {
        $secrets[] = (string) $user->token;
    }
    check(
        'login_returns_bearer_only_session_dto',
        $login->getStatusCode() === 200
        && ($loginBody['data']['tokenType'] ?? null) === 'Bearer'
        && $token !== ''
        && isset($loginBody['data']['expiresAtEpochMs'])
        && !isset($loginBody['data']['token'], $loginBody['data']['auth_data'], $loginBody['data']['subscriptionToken'])
        && $user instanceof \App\Models\User
        && $token !== (string) $user->token,
        ['loginHttp' => $login->getStatusCode()]
    );

    $wrong = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => 'wrong-pass-1']);
    check(
        'wrong_password_is_auth_credentials_invalid',
        (bodyOf($wrong)['errorCode'] ?? null) === 'AUTH_CREDENTIALS_INVALID',
        ['code' => bodyOf($wrong)['errorCode'] ?? null]
    );

    $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    $session = httpRequest('GET', '/api/mobile/v1/auth/session', $auth);
    $sessionV0 = httpRequest('GET', '/api/mobile/v0/auth/session', $auth);
    $sessionBody = bodyOf($session);
    $sessionV0Body = bodyOf($sessionV0);
    check(
        'session_restore_works_on_v1_and_v0',
        $session->getStatusCode() === 200
        && ($sessionBody['data']['valid'] ?? null) === true
        && isset($sessionBody['data']['expiresAtEpochMs'])
        && $sessionV0->getStatusCode() === 200
        && ($sessionV0Body['data']['valid'] ?? null) === true
        && !array_key_exists('apiVersion', $sessionV0Body)
        && !isset($sessionBody['data']['sanctumToken'], $sessionBody['data']['token']),
        ['s1' => $session->getStatusCode(), 's0' => $sessionV0->getStatusCode()]
    );

    if ($user) {
        $user->tokens()->update(['expires_at' => now()->subMinute()]);
    }
    $expired = httpRequest('GET', '/api/mobile/v1/auth/session', $auth);
    check(
        'expired_token_maps_auth_session_invalid',
        $expired->getStatusCode() === 403
        && (bodyOf($expired)['errorCode'] ?? null) === 'AUTH_SESSION_INVALID',
        ['http' => $expired->getStatusCode(), 'code' => bodyOf($expired)['errorCode'] ?? null]
    );

    $login2 = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $password]);
    $token2 = (string) (bodyOf($login2)['data']['sanctumToken'] ?? '');
    $secrets[] = $token2;
    $logout = httpRequest('POST', '/api/mobile/v1/auth/logout', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token2]);
    $logoutBody = bodyOf($logout);
    $afterLogout = httpRequest('GET', '/api/mobile/v1/auth/session', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token2]);
    check(
        'logout_revokes_current_session_and_requires_vpn_stop',
        $logout->getStatusCode() === 200
        && ($logoutBody['data']['mustStopVpn'] ?? null) === true
        && ($logoutBody['data']['mustClearSensitiveData'] ?? null) === true
        && (bodyOf($afterLogout)['errorCode'] ?? null) === 'AUTH_SESSION_INVALID',
        ['logoutHttp' => $logout->getStatusCode(), 'after' => bodyOf($afterLogout)['errorCode'] ?? null]
    );

    \Illuminate\Support\Facades\Queue::fake();
    $codeResp = httpRequest('POST', '/api/mobile/v1/auth/email-code', [], ['email' => $email]);
    $codeBody = bodyOf($codeResp);
    $cachedCode = \Illuminate\Support\Facades\Cache::get(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $email));
    check(
        'email_code_accepted_without_returning_code',
        $codeResp->getStatusCode() === 200
        && ($codeBody['data']['accepted'] ?? null) === true
        && !isset($codeBody['data']['code'])
        && is_numeric($cachedCode),
        ['emailHttp' => $codeResp->getStatusCode()]
    );

    $newPassword = 'task022-new1';
    $reset = httpRequest('POST', '/api/mobile/v1/auth/password-reset', [], [
        'email' => $email,
        'email_code' => (string) $cachedCode,
        'password' => $newPassword,
    ]);
    $relogin = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $newPassword]);
    $reloginToken = (string) (bodyOf($relogin)['data']['sanctumToken'] ?? '');
    $secrets[] = $reloginToken;
    check(
        'password_reset_then_login_with_new_password',
        $reset->getStatusCode() === 200
        && (bodyOf($reset)['data']['accepted'] ?? null) === true
        && $relogin->getStatusCode() === 200
        && $reloginToken !== '',
        ['resetHttp' => $reset->getStatusCode(), 'loginHttp' => $relogin->getStatusCode()]
    );

    $unknownReset = httpRequest('POST', '/api/mobile/v1/auth/password-reset', [], [
        'email' => 'missing-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'email_code' => '123456',
        'password' => $password,
    ]);
    $badCode = httpRequest('POST', '/api/mobile/v1/auth/password-reset', [], [
        'email' => $email,
        'email_code' => '000000',
        'password' => $password,
    ]);
    $unknownBody = bodyOf($unknownReset);
    $badCodeBody = bodyOf($badCode);
    check(
        'unknown_email_reset_matches_wrong_code_without_enumeration',
        ($unknownBody['errorCode'] ?? null) === 'AUTH_EMAIL_CODE_INVALID'
        && ($badCodeBody['errorCode'] ?? null) === 'AUTH_EMAIL_CODE_INVALID'
        && $unknownReset->getStatusCode() === $badCode->getStatusCode(),
        ['unknown' => $unknownBody['errorCode'] ?? null, 'wrong' => $badCodeBody['errorCode'] ?? null]
    );
    check(
        'wrong_email_code_is_auth_email_code_invalid',
        ($badCodeBody['errorCode'] ?? null) === 'AUTH_EMAIL_CODE_INVALID',
        ['code' => $badCodeBody['errorCode'] ?? null]
    );

    admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);
    $captchaLogin = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $newPassword]);
    admin_setting(['captcha_enable' => 0]);
    check(
        'public_login_enforces_captcha_when_enabled',
        (bodyOf($captchaLogin)['errorCode'] ?? null) === 'AUTH_CAPTCHA_FAILED',
        ['code' => bodyOf($captchaLogin)['errorCode'] ?? null]
    );

    $bannedEmail = 'banned-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $banned = (new \App\Services\UserService())->createUser(['email' => $bannedEmail, 'password' => $password]);
    $banned->banned = true;
    $banned->save();
    $bannedLogin = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $bannedEmail, 'password' => $password]);
    check(
        'banned_login_is_auth_account_banned',
        (bodyOf($bannedLogin)['errorCode'] ?? null) === 'AUTH_ACCOUNT_BANNED',
        ['code' => bodyOf($bannedLogin)['errorCode'] ?? null]
    );

    \Illuminate\Support\Facades\Cache::put(\Plugin\MobileApp\Support\MobileAuthThrottle::cacheKey('login', '127.0.0.1'), 30, 600);
    $throttled = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $email, 'password' => $newPassword]);
    check(
        'public_ip_rate_limit_returns_auth_rate_limited',
        (bodyOf($throttled)['errorCode'] ?? null) === 'AUTH_RATE_LIMITED',
        ['code' => bodyOf($throttled)['errorCode'] ?? null]
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
    'taskId' => 'TASK-022',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
