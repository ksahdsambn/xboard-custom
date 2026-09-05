<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task026.sqlite',
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

function makeNotice(string $title, bool $show, int $sort, string $content): \App\Models\Notice
{
    $notice = new \App\Models\Notice();
    $notice->title = $title;
    $notice->content = $content;
    $notice->show = $show;
    $notice->sort = $sort;
    $notice->save();
    return $notice;
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

    $password = 'task026-pass';
    $userA = makeUser('a-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $userB = makeUser('b-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $tokenA = loginToken($userA->email, $password);
    $tokenB = loginToken($userB->email, $password);
    $authA = authHeaders($tokenA);
    $authB = authHeaders($tokenB);

    $empty = httpRequest('GET', '/api/mobile/v1/notices', $authA);
    $emptyBody = bodyOf($empty);
    check(
        'empty_notice_list',
        $empty->getStatusCode() === 200
        && ($emptyBody['data']['total'] ?? null) === 0
        && ($emptyBody['data']['items'] ?? null) === [],
        ['status' => $empty->getStatusCode()]
    );

    $hidden = makeNotice('hidden', false, 0, 'hidden-body');
    $new = makeNotice('new', true, 1, '<script>alert(1)</script><p onclick="x">hello</p><a href="javascript:alert(1)">bad</a><a href="https://example.invalid">ok</a>');
    $mid = makeNotice('mid', true, 1, 'mid-body');
    $old = makeNotice('old', true, 2, 'old-body');

    $page1 = httpRequest('GET', '/api/mobile/v1/notices', $authA, null, ['page' => 1, 'perPage' => 2]);
    $page2 = httpRequest('GET', '/api/mobile/v1/notices', $authA, null, ['page' => 2, 'perPage' => 2]);
    $p1 = bodyOf($page1)['data'] ?? [];
    $p2 = bodyOf($page2)['data'] ?? [];
    $titles1 = array_column($p1['items'] ?? [], 'title');
    $titles2 = array_column($p2['items'] ?? [], 'title');
    check(
        'hidden_excluded_single_and_multi_page_sort',
        ($p1['total'] ?? null) === 3
        && $titles1 === ['mid', 'new']
        && $titles2 === ['old']
        && !in_array('hidden', array_merge($titles1, $titles2), true),
        ['p1' => $titles1, 'p2' => $titles2]
    );

    $opaque = \Plugin\MobileApp\Adapters\NoticeAdapter::opaqueNoticeId((int) $new->id);
    $detail = httpRequest('GET', '/api/mobile/v1/notices/' . $opaque, $authA);
    $detailBody = bodyOf($detail);
    $dto = $detailBody['data'] ?? [];
    $encoded = json_encode($detailBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'detail_sanitizes_html_and_omits_model_fields',
        $detail->getStatusCode() === 200
        && ($dto['title'] ?? null) === 'new'
        && ($dto['read'] ?? null) === false
        && str_contains((string) ($dto['body'] ?? ''), 'hello')
        && str_contains((string) ($dto['body'] ?? ''), 'https://example.invalid')
        && !str_contains(strtolower((string) ($dto['body'] ?? '')), 'script')
        && !str_contains(strtolower((string) ($dto['body'] ?? '')), 'onclick')
        && !str_contains(strtolower((string) ($dto['body'] ?? '')), 'javascript:')
        && !str_contains($encoded, 'img_url')
        && !isset($dto['content'], $dto['show'], $dto['tags'], $dto['img_url']),
        ['keys' => array_keys(is_array($dto) ? $dto : [])]
    );

    $missing = httpRequest('GET', '/api/mobile/v1/notices/does-not-exist', $authA);
    $missingBody = bodyOf($missing);
    check(
        'missing_notice_is_not_found',
        $missing->getStatusCode() === 404
        && ($missingBody['errorCode'] ?? null) === 'NOTICE_NOT_FOUND'
        && ($missingBody['status'] ?? null) === 'fail'
        && array_key_exists('data', $missingBody)
        && $missingBody['data'] === null,
        ['code' => $missingBody['errorCode'] ?? null, 'http' => $missing->getStatusCode(), 'keys' => array_keys($missingBody)]
    );

    $hiddenOpaque = \Plugin\MobileApp\Adapters\NoticeAdapter::opaqueNoticeId((int) $hidden->id);
    $hiddenGet = httpRequest('GET', '/api/mobile/v1/notices/' . $hiddenOpaque, $authA);
    check(
        'hidden_notice_detail_not_found',
        $hiddenGet->getStatusCode() === 404
        && (bodyOf($hiddenGet)['errorCode'] ?? null) === 'NOTICE_NOT_FOUND',
        ['status' => $hiddenGet->getStatusCode()]
    );

    $read1 = httpRequest('POST', '/api/mobile/v1/notices/' . $opaque . '/read', $authA);
    $read2 = httpRequest('POST', '/api/mobile/v1/notices/' . $opaque . '/read', $authA);
    $listA = httpRequest('GET', '/api/mobile/v1/notices', $authA, null, ['page' => 1, 'perPage' => 20]);
    $listB = httpRequest('GET', '/api/mobile/v1/notices', $authB, null, ['page' => 1, 'perPage' => 20]);
    $itemsA = bodyOf($listA)['data']['items'] ?? [];
    $itemsB = bodyOf($listB)['data']['items'] ?? [];
    $readA = false;
    foreach ($itemsA as $item) {
        if (($item['id'] ?? '') === $opaque) {
            $readA = (bool) ($item['read'] ?? false);
        }
    }
    $readB = true;
    foreach ($itemsB as $item) {
        if (($item['id'] ?? '') === $opaque) {
            $readB = (bool) ($item['read'] ?? true);
        }
    }
    check(
        'read_is_idempotent_and_not_shared_across_users',
        $read1->getStatusCode() === 200
        && $read2->getStatusCode() === 200
        && (bodyOf($read1)['data']['read'] ?? null) === true
        && (bodyOf($read2)['data']['read'] ?? null) === true
        && $readA === true
        && $readB === false,
        ['a' => $readA, 'b' => $readB]
    );

    $v0 = httpRequest('GET', '/api/mobile/v0/notices', $authA);
    $v1 = httpRequest('GET', '/api/mobile/v1/notices', $authA);
    check(
        'current_and_previous_clients_read_same_notices',
        $v0->getStatusCode() === 200
        && $v1->getStatusCode() === 200
        && (bodyOf($v1)['apiVersion'] ?? null) === 1
        && !array_key_exists('apiVersion', bodyOf($v0))
        && (bodyOf($v0)['data']['total'] ?? null) === (bodyOf($v1)['data']['total'] ?? null),
        ['v0' => $v0->getStatusCode(), 'v1' => $v1->getStatusCode()]
    );

    $blob = json_encode([$emptyBody, $p1, $detailBody, bodyOf($listA), bodyOf($listB)], JSON_UNESCAPED_UNICODE) ?: '';
    $leaked = false;
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($blob, $secret)) {
            $leaked = true;
        }
    }
    check('notice_responses_omit_session_secrets', !$leaked, ['leaked' => $leaked]);
} catch (\Throwable $e) {
    check('runtime_exception', false, ['class' => $e::class, 'message' => $e->getMessage()]);
}

$failed = array_values(array_filter($tests, static fn(array $item): bool => !$item['passed']));
echo json_encode([
    'taskId' => 'TASK-026',
    'status' => $failed ? 'failed' : 'passed',
    'formalAcceptanceClaimed' => false,
    'deviceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit($failed ? 1 : 0);
