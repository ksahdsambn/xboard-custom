<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task029.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'confirmationtoken'], true)) {
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

function makePlan(string $name, int $groupId): \App\Models\Plan
{
    $plan = new \App\Models\Plan();
    $plan->name = $name;
    $plan->group_id = $groupId;
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

function compatibleSettings(): array
{
    return [
        'tls' => 2,
        'network' => 'tcp',
        'flow' => 'xtls-rprx-vision',
        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        'reality_settings' => [
            'server_name' => 'www.example.invalid',
            'public_key' => 'fixture-public-key-task029',
            'short_id' => 'ab12',
            'private_key' => 'fixture-private-key-must-never-leave-029',
            'allow_insecure' => false,
        ],
    ];
}

function makeServer(string $name, int $groupId): \App\Models\Server
{
    $server = new \App\Models\Server();
    $server->type = 'vless';
    $server->name = $name;
    $server->host = '203.0.113.29';
    $server->port = '443';
    $server->server_port = 443;
    $server->group_ids = [(string) $groupId];
    $server->show = true;
    $server->rate = 1;
    $server->tags = ['hk'];
    $server->sort = 1;
    $server->u = 0;
    $server->d = 0;
    $server->transfer_enable = 0;
    $server->protocol_settings = compatibleSettings();
    $server->save();
    return $server;
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
        'ticket_must_wait_reply' => 0,
    ]);

    $password = 'task029-pass';
    $originalEmail = 'del-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $users = new \App\Services\UserService();
    $plan = makePlan('delete-plan', 2901);
    $user = makeUser($originalEmail, $password);
    $users->assignPlan($user, $plan, 30);
    $user->telegram_id = 123456;
    $user->save();
    makeServer('delete-node', 2901);
    $token = loginToken($originalEmail, $password);
    $auth = authHeaders($token);
    $secrets[] = (string) $user->uuid;

    $order = new \App\Models\Order();
    $order->user_id = $user->id;
    $order->plan_id = $plan->id;
    $order->period = 'month_price';
    $order->trade_no = 'T029' . bin2hex(random_bytes(4));
    $order->total_amount = 199;
    $order->type = 1;
    $order->status = 3;
    $order->save();

    \Plugin\MobileApp\Models\PurchaseToken::query()->create([
        'user_id' => $user->id,
        'platform' => 'google_play',
        'purchase_token_hash' => hash('sha256', 'fixture-play-token-029'),
        'product_id' => 'sku.month',
        'package_name' => 'dev.xboard.xboard_mobile',
        'environment' => 'testing',
        'play_status' => 'purchased',
        'acknowledged' => true,
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);
    \Plugin\MobileApp\Models\AccountLink::query()->create([
        'user_id' => $user->id,
        'platform' => 'google_play',
        'obfuscated_account_id' => 'obf-' . $user->id,
        'environment' => 'testing',
        'status' => 'active',
        'request_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);
    $notice = new \App\Models\Notice();
    $notice->title = 'notice-029';
    $notice->content = 'body';
    $notice->show = true;
    $notice->sort = 1;
    $notice->save();
    $opaqueNotice = \Plugin\MobileApp\Adapters\NoticeAdapter::opaqueNoticeId((int) $notice->id);
    httpRequest('POST', '/api/mobile/v1/notices/' . $opaqueNotice . '/read', $auth);
    httpRequest('PUT', '/api/mobile/v1/devices', $auth, [
        'opaqueDeviceId' => 'device-task029-01',
        'platform' => 'android',
        'appVersion' => '1.0.0',
        'androidApi' => 26,
        'mobileApiVersion' => 1,
        'profileSchemaVersion' => 1,
        'libxrayVersion' => '15e88365296a6f955e5e38caa2d02c97b499733f',
        'xrayCoreVersion' => 'v26.7.28',
    ]);
    $ticket = httpRequest('POST', '/api/mobile/v1/tickets', $auth, ['subject' => 'keep-private', 'level' => 1, 'message' => 'personal-ticket-body']);

    $accountBefore = httpRequest('GET', '/api/mobile/v1/account', $auth);
    $nodesBefore = httpRequest('GET', '/api/mobile/v1/nodes', $auth);
    $opaqueNode = (string) (bodyOf($nodesBefore)['data']['items'][0]['opaqueNodeId'] ?? '');
    $profileBefore = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaqueNode, $auth);
    check(
        'pre_delete_account_nodes_profile_available',
        $accountBefore->getStatusCode() === 200
        && $nodesBefore->getStatusCode() === 200
        && $profileBefore->getStatusCode() === 200
        && $opaqueNode !== ''
        && (bodyOf($profileBefore)['data']['protocol'] ?? null) === 'vless',
        ['nodes' => $nodesBefore->getStatusCode(), 'profile' => $profileBefore->getStatusCode()]
    );

    $none = httpRequest('GET', '/api/mobile/v1/account/deletion', $auth);
    $legal = httpRequest('GET', '/api/mobile/v1/legal/account-deletion');
    check(
        'status_none_and_legal_play_warning',
        $none->getStatusCode() === 200 && (bodyOf($none)['data']['status'] ?? null) === 'none'
        && $legal->getStatusCode() === 200
        && str_contains((string) (bodyOf($legal)['data']['playSubscriptionWarning'] ?? ''), 'does not cancel Play')
        && str_contains((string) (bodyOf($legal)['data']['playSubscriptionManagementUrl'] ?? ''), 'play.google.com/store/account/subscriptions'),
        ['status' => bodyOf($none)['data']['status'] ?? null]
    );

    $preview1 = httpRequest('POST', '/api/mobile/v1/account/deletion/preview', $auth);
    $preview2 = httpRequest('POST', '/api/mobile/v1/account/deletion/preview', $auth);
    $confirm = (string) (bodyOf($preview2)['data']['confirmationToken'] ?? '');
    $secrets[] = $confirm;
    $pending = httpRequest('GET', '/api/mobile/v1/account/deletion', $auth);
    check(
        'preview_repeat_and_pending_status',
        $preview1->getStatusCode() === 200 && $preview2->getStatusCode() === 200
        && (bodyOf($preview2)['data']['requiresConfirmation'] ?? null) === true
        && str_contains((string) (bodyOf($preview2)['data']['playSubscriptionWarning'] ?? ''), 'does not cancel Play')
        && $confirm !== ''
        && (bodyOf($pending)['data']['status'] ?? null) === 'pending',
        ['pending' => bodyOf($pending)['data']['status'] ?? null]
    );

    $wrongPassword = httpRequest('POST', '/api/mobile/v1/account/deletion', $auth, [
        'password' => 'wrong-pass-029',
        'confirmationToken' => $confirm,
        'playSubscriptionWarningAck' => true,
    ]);
    $noAck = httpRequest('POST', '/api/mobile/v1/account/deletion', $auth, [
        'password' => $password,
        'confirmationToken' => $confirm,
        'playSubscriptionWarningAck' => false,
    ]);
    $badToken = httpRequest('POST', '/api/mobile/v1/account/deletion', $auth, [
        'password' => $password,
        'confirmationToken' => 'not-the-confirmation-token',
        'playSubscriptionWarningAck' => true,
    ]);
    check(
        'identity_ack_and_token_failures',
        $wrongPassword->getStatusCode() === 400 && (bodyOf($wrongPassword)['errorCode'] ?? null) === 'AUTH_CREDENTIALS_INVALID'
        && $noAck->getStatusCode() === 400 && (bodyOf($noAck)['errorCode'] ?? null) === 'DELETION_PLAY_WARNING_REQUIRED'
        && $badToken->getStatusCode() === 400 && (bodyOf($badToken)['errorCode'] ?? null) === 'DELETION_CONFIRMATION_INVALID',
        ['pw' => bodyOf($wrongPassword)['errorCode'] ?? null, 'ack' => bodyOf($noAck)['errorCode'] ?? null]
    );

    $execute = httpRequest('POST', '/api/mobile/v1/account/deletion', $auth, [
        'password' => $password,
        'confirmationToken' => $confirm,
        'playSubscriptionWarningAck' => true,
    ]);
    $executeBody = bodyOf($execute);
    $fresh = \App\Models\User::query()->find($user->id);
    $repeatService = (new \Plugin\MobileApp\Services\AccountDeletionService())->execute($fresh, [
        'password' => 'ignored',
        'confirmationToken' => 'ignored',
        'playSubscriptionWarningAck' => false,
    ]);
    $repeatHttp = httpRequest('POST', '/api/mobile/v1/account/deletion', $auth, [
        'password' => $password,
        'confirmationToken' => $confirm,
        'playSubscriptionWarningAck' => true,
    ]);
    check(
        'execute_and_repeat_are_safe',
        $execute->getStatusCode() === 200
        && ($executeBody['data']['status'] ?? null) === 'executed'
        && ($executeBody['data']['mustStopVpn'] ?? null) === true
        && ($executeBody['data']['mustClearSensitiveData'] ?? null) === true
        && ($repeatService['status'] ?? null) === 'executed'
        && $repeatHttp->getStatusCode() !== 500
        && in_array(bodyOf($repeatHttp)['errorCode'] ?? null, ['AUTH_SESSION_INVALID', null], true),
        ['httpRepeat' => $repeatHttp->getStatusCode(), 'code' => bodyOf($repeatHttp)['errorCode'] ?? null]
    );

    $loginAgain = httpRequest('POST', '/api/mobile/v1/auth/login', [], ['email' => $originalEmail, 'password' => $password]);
    $sessionAfter = httpRequest('GET', '/api/mobile/v1/auth/session', $auth);
    $accountAfter = httpRequest('GET', '/api/mobile/v1/account', $auth);
    $nodesAfter = httpRequest('GET', '/api/mobile/v1/nodes', $auth);
    $profileAfter = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaqueNode, $auth);
    check(
        'login_session_account_nodes_profile_unavailable_after_delete',
        $loginAgain->getStatusCode() !== 200
        && (bodyOf($loginAgain)['errorCode'] ?? null) === 'AUTH_CREDENTIALS_INVALID'
        && $sessionAfter->getStatusCode() !== 200
        && (bodyOf($sessionAfter)['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && $accountAfter->getStatusCode() !== 200
        && (bodyOf($accountAfter)['errorCode'] ?? null) === 'AUTH_SESSION_INVALID'
        && $nodesAfter->getStatusCode() !== 200
        && $profileAfter->getStatusCode() !== 200,
        ['login' => bodyOf($loginAgain)['errorCode'] ?? null, 'session' => bodyOf($sessionAfter)['errorCode'] ?? null]
    );

    $fresh = $fresh->fresh();
    $requestRow = \Plugin\MobileApp\Models\DeletionRequest::query()->where('user_id', $user->id)->where('status', 'executed')->first();
    $ticketRow = \App\Models\Ticket::query()->where('user_id', $user->id)->first();
    $message = $ticketRow ? \App\Models\TicketMessage::query()->where('ticket_id', $ticketRow->id)->value('message') : null;
    $link = \Plugin\MobileApp\Models\AccountLink::query()->where('user_id', $user->id)->first();
    check(
        'personal_data_anonymized_and_legal_records_isolated',
        is_string($fresh->email) && str_ends_with($fresh->email, '@invalid.account')
        && $fresh->email !== $originalEmail
        && (bool) $fresh->banned === true
        && $fresh->telegram_id === null
        && (int) ($fresh->plan_id ?? 0) === 0
        && \Plugin\MobileApp\Models\Device::query()->where('user_id', $user->id)->count() === 0
        && \Plugin\MobileApp\Models\NoticeRead::query()->where('user_id', $user->id)->count() === 0
        && \App\Models\Order::query()->where('user_id', $user->id)->count() === 1
        && \Plugin\MobileApp\Models\PurchaseToken::query()->where('user_id', $user->id)->count() === 1
        && $requestRow !== null && $requestRow->retain_until !== null
        && $ticketRow !== null && $ticketRow->subject === '[account-deleted]'
        && $message === '[account-deleted]'
        && $link !== null && $link->status === 'revoked',
        ['emailDomain' => str_ends_with((string) $fresh->email, '@invalid.account'), 'orders' => \App\Models\Order::query()->where('user_id', $user->id)->count()]
    );

    $otherEmail = 'keep-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $other = makeUser($otherEmail, $password);
    $otherAuth = authHeaders(loginToken($otherEmail, $password));
    $v0preview = httpRequest('POST', '/api/mobile/v0/account/deletion/preview', $otherAuth);
    $v0token = (string) (bodyOf($v0preview)['data']['confirmationToken'] ?? '');
    $v0exec = httpRequest('POST', '/api/mobile/v0/account/deletion', $otherAuth, [
        'password' => $password,
        'confirmationToken' => $v0token,
        'playSubscriptionWarningAck' => true,
    ]);
    $blob = json_encode([bodyOf($preview2), bodyOf($execute), bodyOf($legal)], JSON_UNESCAPED_UNICODE) ?: '';
    $logs = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    check(
        'v0_v1_compatible_and_no_secrets',
        $v0preview->getStatusCode() === 200 && $v0exec->getStatusCode() === 200
        && (bodyOf($v0exec)['data']['status'] ?? null) === 'executed'
        && !array_key_exists('apiVersion', bodyOf($v0preview))
        && (bodyOf($execute)['apiVersion'] ?? null) === 1
        && !str_contains($blob, 'fixture-private-key')
        && !str_contains($blob, $password)
        && !str_contains($logs, $password)
        && !str_contains($logs, $token),
        ['v0' => $v0exec->getStatusCode(), 'v1' => $execute->getStatusCode()]
    );
} catch (\Throwable $e) {
    check('runtime_exception', false, ['class' => $e::class, 'message' => $e->getMessage()]);
}

$failed = array_values(array_filter($tests, static fn(array $item): bool => !$item['passed']));
echo json_encode([
    'taskId' => 'TASK-029',
    'status' => $failed ? 'failed' : 'passed',
    'formalAcceptanceClaimed' => false,
    'deviceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit($failed ? 1 : 0);
