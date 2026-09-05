<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task019.sqlite',
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
    $tests[] = compact('name', 'passed', 'details');
}

function httpRequest(string $method, string $path, array $headers = []): \Symfony\Component\HttpFoundation\Response
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $key => $value) {
        $server[$key] = $value;
    }
    $request = \Illuminate\Http\Request::create($path, $method, [], [], [], $server);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}

function bodyOf(\Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true) ?: [];
}

$p0 = [
    'AUTH_SESSION_INVALID' => 403,
    'ENTITLEMENT_NONE' => 403,
    'PROFILE_UNAVAILABLE' => 403,
    'PROFILE_SCHEMA_UNSUPPORTED' => 422,
    'APP_VERSION_UNSUPPORTED' => 426,
    'KERNEL_VERSION_DISABLED' => 403,
    'PURCHASE_PENDING' => 409,
    'PURCHASE_INVALID' => 400,
    'PURCHASE_DUPLICATE' => 409,
    'SERVICE_MAINTENANCE' => 503,
];

try {
    $app = require '/audit/bootstrap/app.php';
    $console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
    config(['cache.stores.redis' => ['driver' => 'array']]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $app->forgetInstance(\App\Support\Setting::class);

    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $manager = new \App\Services\Plugin\PluginManager();
    $manager->install('mobile_app');
    $manager->enable('mobile_app');

    check(
        'fixture_disabled_outside_testing',
        \Plugin\MobileApp\Http\Middleware\MobileEnvelopeMiddleware::allowsFixture('production') === false
        && \Plugin\MobileApp\Http\Middleware\MobileEnvelopeMiddleware::allowsFixture('testing') === true
    );

    $account = httpRequest('GET', '/api/mobile/v1/account');
    $accountJson = bodyOf($account);
    check(
        'unauthenticated_user_route_is_session_invalid_403',
        $account->getStatusCode() === 403
        && ($accountJson['status'] ?? null) === 'fail'
        && ($accountJson['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($accountJson['apiVersion'] ?? null) === 1
        && isset($accountJson['requestId'])
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(403, $accountJson) === 're-login',
        ['http' => $account->getStatusCode(), 'code' => $accountJson['errorCode'] ?? null]
    );

    $session401 = httpRequest('GET', '/api/mobile/v1/bootstrap', [
        'HTTP_X_MOBILE_ERROR_FIXTURE' => 'AUTH_SESSION_INVALID',
        'HTTP_X_MOBILE_ERROR_HTTP' => '401',
    ]);
    $session401Json = bodyOf($session401);
    check(
        'session_invalid_401_is_relogin',
        $session401->getStatusCode() === 401
        && ($session401Json['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(401, $session401Json) === 're-login'
    );

    $entitlement = httpRequest('GET', '/api/mobile/v1/bootstrap', [
        'HTTP_X_MOBILE_ERROR_FIXTURE' => 'ENTITLEMENT_NONE',
    ]);
    $entitlementJson = bodyOf($entitlement);
    check(
        'business_403_is_not_relogin',
        $entitlement->getStatusCode() === 403
        && ($entitlementJson['errorCode'] ?? null) === 'ENTITLEMENT_NONE'
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(403, $entitlementJson) === 'business-reject'
    );

    $p0Failed = [];
    foreach ($p0 as $code => $http) {
        $response = httpRequest('GET', '/api/mobile/v1/bootstrap', [
            'HTTP_X_MOBILE_ERROR_FIXTURE' => $code,
        ]);
        $json = bodyOf($response);
        $ok = $response->getStatusCode() === $http
            && ($json['status'] ?? null) === 'fail'
            && ($json['errorCode'] ?? null) === $code
            && array_key_exists('data', $json)
            && $json['data'] === null
            && is_string($json['requestId'] ?? null)
            && $response->headers->get('X-Request-Id') === ($json['requestId'] ?? null);
        if (!$ok) {
            $p0Failed[] = $code . ':' . $response->getStatusCode() . ':' . ($json['errorCode'] ?? '') . ':data=' . json_encode($json['data'] ?? 'missing');
        }
    }
    check('all_p0_catalog_errors_have_http_machine_code_and_request_id', $p0Failed === [], ['failed' => $p0Failed]);

    $zh = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', [
        'HTTP_X_MOBILE_ERROR_FIXTURE' => 'KERNEL_VERSION_DISABLED',
        'HTTP_X_LOCALE' => 'zh',
    ]));
    $en = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', [
        'HTTP_X_MOBILE_ERROR_FIXTURE' => 'KERNEL_VERSION_DISABLED',
        'HTTP_X_LOCALE' => 'en',
    ]));
    check(
        'locale_changes_message_not_machine_code',
        ($zh['errorCode'] ?? null) === 'KERNEL_VERSION_DISABLED'
        && ($en['errorCode'] ?? null) === 'KERNEL_VERSION_DISABLED'
        && ($zh['message'] ?? '') !== ($en['message'] ?? '')
        && ($zh['message'] ?? '') === '当前内核版本已被禁用'
        && ($en['message'] ?? '') === 'This kernel version is disabled.'
    );

    $adopt = bodyOf(httpRequest('POST', '/api/mobile/v1/auth/login', [
        'HTTP_X_REQUEST_ID' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    ]));
    check(
        'valid_request_id_is_propagated',
        ($adopt['requestId'] ?? null) === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
        && ($adopt['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
    );

    $secret = 'leak-token-019';
    \Plugin\MobileApp\Support\MobileLogRedactor::$sink = [];
    $badId = httpRequest('GET', '/api/mobile/v1/account', [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
        'HTTP_X_REQUEST_ID' => 'Bearer ' . $secret,
        'HTTP_X_FAKE_PROFILE' => json_encode(['privateKey' => 'reality-private-key-example', 'userId' => 'uuid-should-not-echo']),
    ]);
    $badJson = bodyOf($badId);
    $encoded = json_encode($badJson) . \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    check(
        'invalid_request_id_regenerated_and_secrets_not_echoed',
        ($badJson['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && ($badJson['requestId'] ?? '') !== ('Bearer ' . $secret)
        && !str_contains($encoded, $secret)
        && !str_contains($encoded, 'reality-private-key-example')
        && preg_match('/^[0-9a-fA-F-]{36}$/', (string) ($badJson['requestId'] ?? '')) === 1
    );

    $redacted = \Plugin\MobileApp\Support\MobileLogRedactor::redact([
        'authorization' => 'Bearer ' . $secret,
        'privateKey' => 'reality-private-key-example',
        'errorCode' => 'ENTITLEMENT_NONE',
    ]);
    check(
        'redactor_masks_sensitive_keys',
        $redacted['authorization'] === '[redacted]'
        && $redacted['privateKey'] === '[redacted]'
        && $redacted['errorCode'] === 'ENTITLEMENT_NONE'
    );

    $page = \Plugin\MobileApp\Support\MobilePaginator::payload(['n1'], 0, 1000, 1);
    $pageResp = \Plugin\MobileApp\Support\MobileEnvelope::paginate(['n1'], 1, 20, 1);
    $pageJson = json_decode($pageResp->getContent(), true) ?: [];
    check(
        'pagination_envelope_uses_items_page_perpage_total',
        $page === ['items' => ['n1'], 'page' => 1, 'perPage' => 100, 'total' => 1]
        && ($pageJson['status'] ?? null) === 'success'
        && array_keys($pageJson['data'] ?? []) === ['items', 'page', 'perPage', 'total']
    );

    $v0 = bodyOf(httpRequest('POST', '/api/mobile/v0/auth/login'));
    $v1 = bodyOf(httpRequest('POST', '/api/mobile/v1/auth/login'));
    check(
        'v0_and_v1_envelopes_keep_xboard_outer_semantics',
        ($v0['status'] ?? null) === 'fail'
        && ($v0['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
        && !array_key_exists('apiVersion', $v0)
        && ($v1['apiVersion'] ?? null) === 1
        && array_key_exists('error', $v0)
        && array_key_exists('data', $v0)
    );

    $mapped403 = \Plugin\MobileApp\Support\MobileErrorMapper::fromThrowable(
        new \App\Exceptions\ApiException('未登录或登陆已过期', 403)
    );
    $mapped401 = \Plugin\MobileApp\Support\MobileErrorMapper::fromThrowable(
        new \Illuminate\Auth\AuthenticationException()
    );
    $mappedMaint = \Plugin\MobileApp\Support\MobileErrorMapper::fromThrowable(
        new \App\Exceptions\ApiException('服务器正在维护，暂不可用', 500002)
    );
    $unauthReq = \Illuminate\Http\Request::create('/api/mobile/v1/account', 'GET');
    $unauthResp = new \Illuminate\Http\Response(json_encode(['message' => 'Unauthenticated.']), 401);
    $mappedUnauth = \Plugin\MobileApp\Support\MobileErrorMapper::fromOfficialResponse(
        $unauthReq,
        $unauthResp,
        ['message' => 'Unauthenticated.']
    );
    check(
        'xboard_exceptions_translate_without_using_client_locale',
        $mapped403 === ['errorCode' => 'AUTH_SESSION_INVALID', 'http' => 403]
        && $mapped401 === ['errorCode' => 'AUTH_SESSION_INVALID', 'http' => 401]
        && $mappedMaint === ['errorCode' => 'SERVICE_MAINTENANCE', 'http' => 503]
        && $mappedUnauth === ['errorCode' => 'AUTH_SESSION_INVALID', 'http' => 401]
    );

    $admin = httpRequest('GET', '/api/mobile/v1/admin/compat');
    $adminJson = bodyOf($admin);
    check(
        'admin_unauthorized_without_session_is_relogin_not_generic_403',
        $admin->getStatusCode() === 403
        && ($adminJson['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(403, $adminJson) === 're-login'
    );

    $rtdn = httpRequest('POST', '/api/mobile/v1/platform/google/rtdn');
    $rtdnJson = bodyOf($rtdn);
    check(
        'platform_401_purchase_invalid_is_not_relogin',
        $rtdn->getStatusCode() === 401
        && ($rtdnJson['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(401, $rtdnJson) !== 're-login'
    );

    $official = httpRequest('POST', '/api/v1/passport/auth/login');
    $officialJson = bodyOf($official);
    check(
        'official_web_login_is_not_rewritten_with_mobile_error_code',
        $official->getStatusCode() !== 404
        && ($officialJson['errorCode'] ?? null) === null,
        ['status' => $official->getStatusCode()]
    );

    $profile404 = httpRequest('GET', '/api/mobile/v1/bootstrap', [
        'HTTP_X_MOBILE_ERROR_FIXTURE' => 'PROFILE_UNAVAILABLE',
        'HTTP_X_MOBILE_ERROR_HTTP' => '404',
    ]);
    check(
        'allowed_alternate_http_for_profile_unavailable',
        $profile404->getStatusCode() === 404
        && (bodyOf($profile404)['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE'
        && \Plugin\MobileApp\Support\MobileClientDecision::decide(404, bodyOf($profile404)) !== 're-login'
    );
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'line' => $exception->getLine()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-019',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
