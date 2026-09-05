<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task032.sqlite',
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

function rtdnPayload(string $eventId, string $purchaseToken, int $eventTime, int $claimedType, string $environment = 'sandbox', string $package = 'dev.xboard.xboard_mobile', array $extra = []): array
{
    $decoded = array_merge([
        'version' => '1.0',
        'packageName' => $package,
        'eventTimeMillis' => (string) $eventTime,
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => $claimedType,
            'purchaseToken' => $purchaseToken,
            'subscriptionId' => 'dev.xboard.sub.monthly',
            'playStatus' => 'purchased',
            'entitlement' => 'premium',
            'planId' => 99,
            'expiresAt' => '2099-01-01T00:00:00Z',
        ],
    ], $extra);
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

function postRtdn(array $payload, array $headerOverride = [], string $path = '/api/mobile/v1/platform/google/rtdn'): \Symfony\Component\HttpFoundation\Response
{
    $raw = json_encode($payload);
    $headers = array_merge([
        'HTTP_X_MOBILE_RTDN_TEST' => 'fixture-ok',
        'HTTP_X_GOOG_CHANNEL_TOKEN' => 'rtdn-sandbox-channel',
        'HTTP_X_MOBILE_RTDN_SIGNATURE' => hash('sha256', 'rtdn-sandbox-channel.' . $raw),
    ], $headerOverride);
    return httpRequest('POST', $path, $headers, $payload);
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
    $password = 'task032-pass';
    $plan = makePlan('play-mapped');
    $admin = makeUser('admin-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $admin->is_admin = true;
    $admin->save();
    $user = makeUser('buyer-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $adminAuth = authHeaders(loginToken($admin->email, $password));
    $userAuth = authHeaders(loginToken($user->email, $password));

    httpRequest('PUT', '/api/mobile/v1/admin/play-products', $adminAuth, [
        'packageName' => 'dev.xboard.xboard_mobile',
        'productId' => 'dev.xboard.sub.monthly',
        'basePlanId' => 'p1m',
        'environment' => 'sandbox',
        'xboardPlanId' => $plan->id,
        'enabled' => true,
    ]);

    $obf = 'obf-account-' . bin2hex(random_bytes(4));
    $submit = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-purchased',
        'obfuscatedAccountId' => $obf,
    ]);
    $submitOk = $submit->getStatusCode() === 200 && (bodyOf($submit)['data']['playStatus'] ?? null) === 'purchased';

    $valid = postRtdn(rtdnPayload('evt-valid', 'tok-purchased', 200, 2));
    $validBody = bodyOf($valid);
    $validEvent = \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-valid')->first();
    $ledger = \Plugin\MobileApp\Models\PurchaseToken::query()->where('purchase_token_hash', hash('sha256', 'tok-purchased'))->first();
    $user->refresh();
    check(
        'valid_rtdn_rechecks_developer_api_and_accepts',
        $submitOk
        && $valid->getStatusCode() === 200
        && ($validBody['data']['accepted'] ?? null) === true
        && !isset($validBody['data']['purchaseToken'])
        && $validEvent instanceof \Plugin\MobileApp\Models\RtdnEvent
        && $validEvent->processing_status === 'processed'
        && $validEvent->play_status_applied === 'purchased'
        && (int) $validEvent->apply_count === 1
        && $ledger instanceof \Plugin\MobileApp\Models\PurchaseToken
        && $ledger->play_status === 'purchased',
        ['http' => $valid->getStatusCode(), 'applied' => $validEvent->play_status_applied ?? null, 'apply' => $validEvent->apply_count ?? null]
    );

    $badSig = postRtdn(rtdnPayload('evt-bad-sig', 'tok-purchased', 1, 2), ['HTTP_X_MOBILE_RTDN_SIGNATURE' => 'deadbeef']);
    check(
        'invalid_signature_rejected',
        (bodyOf($badSig)['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && $badSig->getStatusCode() === 401
        && !\Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-bad-sig')->exists(),
        ['http' => $badSig->getStatusCode(), 'code' => bodyOf($badSig)['errorCode'] ?? null]
    );

    $wrongEnv = postRtdn(rtdnPayload('evt-prod', 'tok-purchased', 1, 2, 'production'));
    $wrongEnvEvent = \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-prod')->first();
    check(
        'wrong_environment_rejected',
        (bodyOf($wrongEnv)['errorCode'] ?? null) === 'PURCHASE_INVALID'
        && $wrongEnvEvent instanceof \Plugin\MobileApp\Models\RtdnEvent
        && $wrongEnvEvent->processing_status === 'rejected'
        && $wrongEnvEvent->last_error === 'ENV_MISMATCH'
        && $ledger->fresh()->play_status === 'purchased',
        ['code' => bodyOf($wrongEnv)['errorCode'] ?? null, 'status' => $wrongEnvEvent->processing_status ?? null]
    );

    $dup = postRtdn(rtdnPayload('evt-valid', 'tok-purchased', 999, 4));
    $validEvent->refresh();
    check(
        'duplicate_event_id_is_idempotent',
        $dup->getStatusCode() === 200
        && (bodyOf($dup)['data']['accepted'] ?? null) === true
        && (int) $validEvent->apply_count === 1
        && \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-valid')->count() === 1,
        ['http' => $dup->getStatusCode(), 'apply' => $validEvent->apply_count]
    );

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->setStatus('tok-purchased', 'expired');
    $later = postRtdn(rtdnPayload('evt-later', 'tok-purchased', 300, 12));
    $earlier = postRtdn(rtdnPayload('evt-earlier', 'tok-purchased', 100, 2));
    $laterEvent = \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-later')->first();
    $earlierEvent = \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-earlier')->first();
    check(
        'out_of_order_and_delayed_events_do_not_rollback',
        $later->getStatusCode() === 200
        && $earlier->getStatusCode() === 200
        && $ledger->fresh()->play_status === 'expired'
        && ($laterEvent->play_status_applied ?? null) === 'expired'
        && ($earlierEvent->play_status_applied ?? null) === 'expired'
        && (int) $laterEvent->apply_count === 1
        && (int) $earlierEvent->apply_count === 0,
        ['ledger' => $ledger->fresh()->play_status, 'laterApply' => $laterEvent->apply_count ?? null, 'earlierApply' => $earlierEvent->apply_count ?? null]
    );

    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::reset();
    httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth, [
        'productId' => 'dev.xboard.sub.monthly',
        'purchaseToken' => 'tok-canceled',
        'obfuscatedAccountId' => $obf,
    ]);
    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->failNext(1);
    $retryFirst = postRtdn(rtdnPayload('evt-retry', 'tok-canceled', 1, 3));
    $retryRow = \Plugin\MobileApp\Models\RtdnEvent::query()->where('event_id', 'evt-retry')->first();
    $canceledLedger = \Plugin\MobileApp\Models\PurchaseToken::query()->where('purchase_token_hash', hash('sha256', 'tok-canceled'))->first();
    $firstRetryQueued = $retryRow instanceof \Plugin\MobileApp\Models\RtdnEvent
        && $retryRow->processing_status === 'retry'
        && (int) $retryRow->apply_count === 0
        && $canceledLedger instanceof \Plugin\MobileApp\Models\PurchaseToken
        && $canceledLedger->play_status === 'canceled';
    $retryRow->next_retry_at = now()->subMinute();
    $retryRow->save();
    \Plugin\MobileApp\Adapters\PlayDeveloperAdapter::shared()->setStatus('tok-canceled', 'revoked');
    $processed = \Plugin\MobileApp\Services\RtdnService::make()->processDueRetries(time() + 86400);
    $processedAgain = \Plugin\MobileApp\Services\RtdnService::make()->processDueRetries(time() + 86400);
    $retryRow->refresh();
    check(
        'developer_api_failure_retries_and_projects_once',
        $firstRetryQueued
        && $retryFirst->getStatusCode() === 200
        && $processed === 1
        && $processedAgain === 0
        && $retryRow->processing_status === 'processed'
        && (int) $retryRow->apply_count === 1
        && $canceledLedger->fresh()->play_status === 'revoked',
        ['processed' => $processed, 'again' => $processedAgain, 'apply' => $retryRow->apply_count, 'status' => $retryRow->processing_status]
    );

    $v0 = postRtdn(rtdnPayload('evt-v0', 'tok-purchased', 400, 2), [], '/api/mobile/v0/platform/google/rtdn');
    $audit = httpRequest('GET', '/api/mobile/v1/admin/rtdn-events', $adminAuth);
    $auditBody = bodyOf($audit);
    $auditJson = json_encode($auditBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'v0_compat_and_audit_view_omit_tokens',
        $v0->getStatusCode() === 200
        && (bodyOf($v0)['data']['accepted'] ?? null) === true
        && $audit->getStatusCode() === 200
        && isset($auditBody['data']['items'])
        && !str_contains($auditJson, 'tok-purchased')
        && !str_contains($auditJson, 'tok-canceled')
        && !str_contains($auditJson, 'purchaseToken'),
        ['v0' => $v0->getStatusCode(), 'audit' => $audit->getStatusCode(), 'items' => count($auditBody['data']['items'] ?? [])]
    );

    $encoded = json_encode([$validBody, bodyOf($dup), $auditBody], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $hashes = \Plugin\MobileApp\Models\RtdnEvent::query()->pluck('purchase_token_hash')->filter()->all();
    check(
        'responses_and_rows_omit_raw_purchase_token',
        !str_contains($encoded, 'tok-purchased')
        && !in_array('tok-purchased', $hashes, true),
        ['hashOnly' => !in_array('tok-purchased', $hashes, true)]
    );

    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $leaked = false;
    foreach (array_merge($secrets, ['tok-purchased', 'tok-canceled']) as $secret) {
        if ($secret !== '' && str_contains($sink, $secret)) {
            $leaked = true;
            break;
        }
    }
    check('logs_omit_raw_purchase_and_session_tokens', !$leaked, ['leaked' => $leaked]);

    echo json_encode([
        'taskId' => 'TASK-032',
        'status' => array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 'passed' : 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(array_reduce($tests, fn (bool $ok, array $item): bool => $ok && $item['passed'], true) ? 0 : 1);
} catch (\Throwable $exception) {
    echo json_encode([
        'taskId' => 'TASK-032',
        'status' => 'failed',
        'formalAcceptanceClaimed' => false,
        'deviceClaimed' => false,
        'tests' => array_merge($tests, [[
            'name' => 'runtime_exception',
            'passed' => false,
            'details' => ['type' => $exception::class, 'message' => $exception::class === '' ? $exception->getMessage() : $exception->getMessage()],
        ]]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
