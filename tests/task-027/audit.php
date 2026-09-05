<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task027.sqlite',
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
        'ticket_must_wait_reply' => 1,
    ]);

    $password = 'task027-pass';
    $userA = makeUser('a-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $userB = makeUser('b-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $authA = authHeaders(loginToken($userA->email, $password));
    $authB = authHeaders(loginToken($userB->email, $password));

    $empty = httpRequest('POST', '/api/mobile/v1/tickets', $authA, ['subject' => '', 'level' => 1, 'message' => '']);
    $missingLevel = httpRequest('POST', '/api/mobile/v1/tickets', $authA, ['subject' => 'help', 'message' => 'first']);
    check(
        'empty_ticket_rejected',
        $empty->getStatusCode() === 400 && (bodyOf($empty)['errorCode'] ?? null) === 'TICKET_EMPTY',
        ['code' => bodyOf($empty)['errorCode'] ?? null]
    );
    check(
        'missing_level_rejected',
        $missingLevel->getStatusCode() === 400 && (bodyOf($missingLevel)['errorCode'] ?? null) === 'TICKET_EMPTY',
        ['code' => bodyOf($missingLevel)['errorCode'] ?? null]
    );

    $created = httpRequest('POST', '/api/mobile/v1/tickets', $authA, ['subject' => 'help', 'level' => 1, 'message' => 'first']);
    $createdBody = bodyOf($created);
    $ticketId = $createdBody['data']['ticketId'] ?? '';
    check(
        'create_ticket_lifecycle_start',
        $created->getStatusCode() === 200 && ($createdBody['data']['status'] ?? null) === 'open' && is_string($ticketId) && $ticketId !== '',
        ['status' => $created->getStatusCode()]
    );

    $dup = httpRequest('POST', '/api/mobile/v1/tickets', $authA, ['subject' => 'again', 'level' => 1, 'message' => 'second']);
    check(
        'open_ticket_blocks_second_create',
        $dup->getStatusCode() === 409 && (bodyOf($dup)['errorCode'] ?? null) === 'TICKET_OPEN_EXISTS',
        ['code' => bodyOf($dup)['errorCode'] ?? null]
    );

    $wait = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/replies', $authA, ['message' => 'ping']);
    check(
        'wait_reply_blocks_consecutive_user_reply',
        $wait->getStatusCode() === 403 && (bodyOf($wait)['errorCode'] ?? null) === 'TICKET_WAIT_REPLY',
        ['code' => bodyOf($wait)['errorCode'] ?? null]
    );

    $row = \App\Models\Ticket::query()->where('user_id', $userA->id)->first();
    \App\Models\TicketMessage::create(['user_id' => 0, 'ticket_id' => $row->id, 'message' => 'staff']);
    $row->reply_status = \App\Models\Ticket::STATUS_OPENING;
    $row->save();

    $reply = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/replies', $authA, ['message' => 'thanks']);
    $emptyReply = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/replies', $authA, ['message' => '']);
    check(
        'reply_after_staff_and_empty_reply',
        $reply->getStatusCode() === 200 && isset(bodyOf($reply)['data']['messageId'])
        && $emptyReply->getStatusCode() === 400 && (bodyOf($emptyReply)['errorCode'] ?? null) === 'TICKET_EMPTY',
        ['reply' => $reply->getStatusCode()]
    );

    $detail = httpRequest('GET', '/api/mobile/v1/tickets/' . $ticketId, $authA);
    $dto = bodyOf($detail)['data'] ?? [];
    $encoded = json_encode(bodyOf($detail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'detail_has_messages_without_attachments',
        $detail->getStatusCode() === 200
        && ($dto['ticketId'] ?? null) === $ticketId
        && is_array($dto['messages'] ?? null)
        && count($dto['messages']) >= 2
        && !str_contains($encoded, 'attachmentUpload')
        && !isset($dto['attachmentUpload']),
        ['count' => count($dto['messages'] ?? [])]
    );

    $cross = httpRequest('GET', '/api/mobile/v1/tickets/' . $ticketId, $authB);
    $crossReply = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/replies', $authB, ['message' => 'hijack']);
    check(
        'cross_user_read_and_reply_forbidden',
        $cross->getStatusCode() === 403 && (bodyOf($cross)['errorCode'] ?? null) === 'AUTH_FORBIDDEN'
        && $crossReply->getStatusCode() === 403 && (bodyOf($crossReply)['errorCode'] ?? null) === 'AUTH_FORBIDDEN',
        ['read' => bodyOf($cross)['errorCode'] ?? null]
    );

    $missing = httpRequest('GET', '/api/mobile/v1/tickets/missing-ticket', $authA);
    check(
        'missing_ticket_not_found',
        $missing->getStatusCode() === 404 && (bodyOf($missing)['errorCode'] ?? null) === 'TICKET_NOT_FOUND',
        ['code' => bodyOf($missing)['errorCode'] ?? null]
    );

    $close = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/close', $authA);
    $close2 = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/close', $authA);
    $replyClosed = httpRequest('POST', '/api/mobile/v1/tickets/' . $ticketId . '/replies', $authA, ['message' => 'late']);
    check(
        'close_idempotent_error_and_closed_cannot_reply',
        $close->getStatusCode() === 200 && (bodyOf($close)['data']['status'] ?? null) === 'closed'
        && $close2->getStatusCode() === 409 && (bodyOf($close2)['errorCode'] ?? null) === 'TICKET_ALREADY_CLOSED'
        && $replyClosed->getStatusCode() === 403 && (bodyOf($replyClosed)['errorCode'] ?? null) === 'TICKET_CLOSED',
        ['close2' => bodyOf($close2)['errorCode'] ?? null]
    );

    $second = httpRequest('POST', '/api/mobile/v1/tickets', $authA, ['subject' => 'two', 'level' => 2, 'message' => 'next']);
    $list = httpRequest('GET', '/api/mobile/v1/tickets', $authA, null, ['page' => 1, 'perPage' => 1]);
    $listData = bodyOf($list)['data'] ?? [];
    check(
        'list_paginates_own_tickets_after_close',
        $second->getStatusCode() === 200
        && ($listData['total'] ?? 0) >= 2
        && count($listData['items'] ?? []) === 1
        && !isset($listData['attachmentUpload']),
        ['total' => $listData['total'] ?? null]
    );

    $v0 = httpRequest('GET', '/api/mobile/v0/tickets/' . $ticketId, $authA);
    $v1 = httpRequest('GET', '/api/mobile/v1/tickets/' . $ticketId, $authA);
    $v0reply = httpRequest('POST', '/api/mobile/v0/tickets/' . (bodyOf($second)['data']['ticketId'] ?? '') . '/replies', $authA, ['message' => 'v0']);
    check(
        'current_and_previous_clients_read_and_reply',
        $v0->getStatusCode() === 200 && $v1->getStatusCode() === 200
        && (bodyOf($v1)['apiVersion'] ?? null) === 1
        && !array_key_exists('apiVersion', bodyOf($v0))
        && (bodyOf($v0)['data']['ticketId'] ?? null) === (bodyOf($v1)['data']['ticketId'] ?? null)
        && $v0reply->getStatusCode() === 403
        && (bodyOf($v0reply)['errorCode'] ?? null) === 'TICKET_WAIT_REPLY',
        ['v0' => $v0->getStatusCode(), 'v1' => $v1->getStatusCode()]
    );
} catch (\Throwable $e) {
    check('runtime_exception', false, ['class' => $e::class, 'message' => $e->getMessage()]);
}

$failed = array_values(array_filter($tests, static fn(array $item): bool => !$item['passed']));
echo json_encode([
    'taskId' => 'TASK-027',
    'status' => $failed ? 'failed' : 'passed',
    'formalAcceptanceClaimed' => false,
    'deviceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit($failed ? 1 : 0);
