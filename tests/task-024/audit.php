<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task024.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'auth_data', 'subscriptiontoken', 'uuid', 'publickey', 'shortid', 'privatekey'], true)) {
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

function compatibleSettings(array $override = []): array
{
    $settings = [
        'tls' => 2,
        'network' => 'tcp',
        'flow' => 'xtls-rprx-vision',
        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        'reality_settings' => [
            'server_name' => 'www.example.invalid',
            'public_key' => 'fixture-public-key-task024',
            'short_id' => 'ab12',
            'private_key' => 'fixture-private-key-must-never-leave',
            'allow_insecure' => false,
        ],
    ];
    return array_replace_recursive($settings, $override);
}

function makeServer(string $name, array $attrs = []): \App\Models\Server
{
    $server = new \App\Models\Server();
    $server->type = $attrs['type'] ?? 'vless';
    $server->name = $name;
    $server->host = $attrs['host'] ?? '203.0.113.24';
    $server->port = $attrs['port'] ?? '443';
    $server->server_port = $attrs['server_port'] ?? 443;
    $server->group_ids = $attrs['group_ids'] ?? ['2401'];
    $server->show = $attrs['show'] ?? true;
    $server->rate = 1;
    $server->tags = $attrs['tags'] ?? ['hk'];
    $server->sort = $attrs['sort'] ?? 1;
    $server->u = $attrs['u'] ?? 0;
    $server->d = $attrs['d'] ?? 0;
    $server->transfer_enable = $attrs['transfer_enable'] ?? 0;
    $server->protocol_settings = $attrs['protocol_settings'] ?? compatibleSettings();
    $server->save();
    return $server;
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

    $password = 'task024-pass';
    $users = new \App\Services\UserService();
    $planA = makePlan('group-a', 2401);
    $planB = makePlan('group-b', 2402);

    $webUser = makeUser('web-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($webUser, $planA, 30);
    $webUser->save();
    $secrets[] = (string) $webUser->token;
    $secrets[] = (string) $webUser->uuid;

    $otherUser = makeUser('other-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($otherUser, $planB, 30);
    $otherUser->save();
    $secrets[] = (string) $otherUser->uuid;

    $noneUser = makeUser('none-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $expiredUser = makeUser('exp-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($expiredUser, $planA, 10);
    $expiredUser->expired_at = time() - 60;
    $expiredUser->save();
    $exhaustedUser = makeUser('exh-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($exhaustedUser, $planA, 10);
    $exhaustedUser->u = (int) $exhaustedUser->transfer_enable;
    $exhaustedUser->d = 0;
    $exhaustedUser->save();

    $compatible = makeServer('compatible', ['sort' => 1, 'port' => '443']);
    $hidden = makeServer('hidden', ['show' => false, 'sort' => 2]);
    $wrongGroup = makeServer('wrong-group', ['group_ids' => ['2402'], 'sort' => 3, 'tags' => ['jp']]);
    $quota = makeServer('quota', ['transfer_enable' => 100, 'u' => 60, 'd' => 40, 'sort' => 4]);
    $vmess = makeServer('vmess', ['type' => 'vmess', 'sort' => 5, 'protocol_settings' => ['tls' => 1, 'network' => 'tcp']]);
    $ws = makeServer('ws', ['sort' => 6, 'protocol_settings' => compatibleSettings(['network' => 'ws'])]);
    $origin = makeServer('origin', ['sort' => 7, 'protocol_settings' => compatibleSettings(['flow' => 'xtls-rprx-origin'])]);
    $noUtls = makeServer('no-utls', ['sort' => 8, 'protocol_settings' => compatibleSettings(['utls' => ['enabled' => false, 'fingerprint' => 'chrome']])]);
    $randomFp = makeServer('random', ['sort' => 9, 'protocol_settings' => compatibleSettings(['utls' => ['enabled' => true, 'fingerprint' => 'random']])]);
    $insecure = makeServer('insecure', ['sort' => 10, 'protocol_settings' => compatibleSettings(['reality_settings' => ['allow_insecure' => true]])]);
    $dynamic = makeServer('dynamic', ['sort' => 11, 'port' => '24000-24010']);
    $tlsOnly = makeServer('tls-only', ['sort' => 12, 'protocol_settings' => compatibleSettings(['tls' => 1])]);
    $tlsInsecure = makeServer('tls-insecure', ['sort' => 13, 'protocol_settings' => compatibleSettings(['tls_settings' => ['allow_insecure' => true]])]);
    $noHost = makeServer('no-host', ['sort' => 14, 'host' => '']);
    $encrypted = makeServer('encrypted', ['sort' => 15, 'protocol_settings' => compatibleSettings(['encryption' => ['enabled' => true, 'decryption' => 'fixture-decryption-must-never-leave']])]);
    $otherNode = makeServer('other-group-node', ['group_ids' => ['2402'], 'sort' => 1, 'tags' => ['jp']]);

    $secrets[] = 'fixture-public-key-task024';
    $secrets[] = 'fixture-private-key-must-never-leave';
    $secrets[] = 'fixture-decryption-must-never-leave';
    $secrets[] = (string) $compatible->host;

    \Illuminate\Support\Facades\Cache::put(
        \App\Utils\CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $compatible->id),
        time(),
        600
    );

    $webToken = loginToken($webUser->email, $password);
    $webAuth = authHeaders($webToken);
    $nodesV1 = httpRequest('GET', '/api/mobile/v1/nodes', $webAuth);
    $nodesV0 = httpRequest('GET', '/api/mobile/v0/nodes', $webAuth);
    $bodyV1 = bodyOf($nodesV1);
    $bodyV0 = bodyOf($nodesV0);
    $items = $bodyV1['data']['items'] ?? [];
    $names = array_column(is_array($items) ? $items : [], 'name');
    $authorized = \App\Services\ServerService::getAvailableServers($webUser);
    $authorizedNames = array_column($authorized, 'name');
    $extra = array_values(array_diff($names, $authorizedNames));

    check(
        'nodes_work_on_v0_and_v1',
        $nodesV1->getStatusCode() === 200
        && $nodesV0->getStatusCode() === 200
        && ($bodyV1['status'] ?? null) === 'success'
        && ($bodyV0['status'] ?? null) === 'success'
        && ($bodyV1['apiVersion'] ?? null) === 1
        && !array_key_exists('apiVersion', $bodyV0)
        && ($bodyV0['data']['items'][0]['name'] ?? null) === ($items[0]['name'] ?? null),
        ['v0' => $nodesV0->getStatusCode(), 'v1' => $nodesV1->getStatusCode()]
    );
    check(
        'compatible_vless_reality_tcp_vision_listed_in_server_order',
        $names === ['compatible', 'dynamic']
        && ($items[0]['opaqueNodeId'] ?? null) === \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $compatible->id)
        && ($items[0]['region'] ?? null) === 'hk'
        && ($items[0]['available'] ?? null) === true
        && array_key_exists('latencyMs', $items[0])
        && $items[0]['latencyMs'] === null,
        ['names' => $names, 'available' => $items[0]['available'] ?? null]
    );
    check(
        'mobile_result_is_subset_of_server_service_authorization',
        $extra === []
        && in_array('compatible', $authorizedNames, true)
        && in_array('dynamic', $authorizedNames, true)
        && in_array('vmess', $authorizedNames, true)
        && in_array('ws', $authorizedNames, true)
        && !in_array('hidden', $authorizedNames, true)
        && !in_array('wrong-group', $authorizedNames, true)
        && !in_array('quota', $authorizedNames, true)
        && !in_array('other-group-node', $authorizedNames, true)
        && !in_array('vmess', $names, true)
        && !in_array('ws', $names, true)
        && !in_array('origin', $names, true)
        && !in_array('no-utls', $names, true)
        && !in_array('random', $names, true)
        && !in_array('insecure', $names, true)
        && !in_array('tls-only', $names, true)
        && !in_array('tls-insecure', $names, true)
        && !in_array('no-host', $names, true)
        && in_array('tls-insecure', $authorizedNames, true)
        && in_array('no-host', $authorizedNames, true)
        && in_array('encrypted', $authorizedNames, true)
        && !in_array('encrypted', $names, true)
        && !in_array('hidden', $names, true),
        ['authorized' => $authorizedNames, 'mobile' => $names, 'extra' => $extra]
    );

    $dynamicAuthorized = null;
    foreach ($authorized as $row) {
        if (($row['name'] ?? null) === 'dynamic') {
            $dynamicAuthorized = $row;
            break;
        }
    }
    check(
        'dynamic_port_comes_from_server_service_not_dto',
        is_array($dynamicAuthorized)
        && is_int($dynamicAuthorized['port'])
        && $dynamicAuthorized['port'] >= 24000
        && $dynamicAuthorized['port'] <= 24010
        && ($dynamicAuthorized['ports'] ?? null) === '24000-24010'
        && !isset($items[1]['port'], $items[1]['ports'], $items[0]['port']),
        ['dynamicPresent' => is_array($dynamicAuthorized)]
    );

    $encoded = json_encode($bodyV1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $itemKeys = array_keys($items[0] ?? []);
    check(
        'node_summaries_omit_connection_credentials',
        $itemKeys === ['opaqueNodeId', 'name', 'region', 'available', 'latencyMs']
        && !str_contains($encoded, (string) $webUser->uuid)
        && !str_contains($encoded, 'fixture-public-key-task024')
        && !str_contains($encoded, 'fixture-private-key-must-never-leave')
        && !str_contains($encoded, 'fixture-decryption-must-never-leave')
        && !str_contains($encoded, '203.0.113.24')
        && !str_contains($encoded, 'protocol_settings')
        && !str_contains($encoded, 'publicKey')
        && !str_contains($encoded, 'shortId'),
        ['keys' => $itemKeys]
    );

    $noneToken = loginToken($noneUser->email, $password);
    $noneBody = bodyOf(httpRequest('GET', '/api/mobile/v1/nodes', authHeaders($noneToken)));
    check(
        'none_entitlement_cannot_list_nodes',
        ($noneBody['status'] ?? null) === 'fail'
        && ($noneBody['errorCode'] ?? null) === 'ENTITLEMENT_NONE',
        ['code' => $noneBody['errorCode'] ?? null]
    );
    $expiredToken = loginToken($expiredUser->email, $password);
    $expiredBody = bodyOf(httpRequest('GET', '/api/mobile/v1/nodes', authHeaders($expiredToken)));
    check(
        'expired_entitlement_cannot_list_nodes',
        ($expiredBody['errorCode'] ?? null) === 'ENTITLEMENT_EXPIRED',
        ['code' => $expiredBody['errorCode'] ?? null]
    );
    $exhaustedToken = loginToken($exhaustedUser->email, $password);
    $exhaustedBody = bodyOf(httpRequest('GET', '/api/mobile/v1/nodes', authHeaders($exhaustedToken)));
    check(
        'exhausted_entitlement_cannot_list_nodes',
        ($exhaustedBody['errorCode'] ?? null) === 'ENTITLEMENT_EXHAUSTED',
        ['code' => $exhaustedBody['errorCode'] ?? null]
    );

    $otherToken = loginToken($otherUser->email, $password);
    $otherItems = bodyOf(httpRequest('GET', '/api/mobile/v1/nodes', authHeaders($otherToken)))['data']['items'] ?? [];
    $otherNames = array_column(is_array($otherItems) ? $otherItems : [], 'name');
    check(
        'other_group_cannot_see_or_add_unauthorized_nodes',
        $otherNames === ['other-group-node', 'wrong-group']
        && !in_array('compatible', $otherNames, true)
        && !in_array('dynamic', $otherNames, true),
        ['other' => $otherNames]
    );

    $compatible->show = false;
    $compatible->save();
    $afterRevoke = bodyOf(httpRequest('GET', '/api/mobile/v1/nodes', $webAuth))['data']['items'] ?? [];
    $afterNames = array_column(is_array($afterRevoke) ? $afterRevoke : [], 'name');
    check(
        'revoked_hidden_node_cannot_return_from_old_cache',
        $afterNames === ['dynamic']
        && !in_array('compatible', $afterNames, true),
        ['after' => $afterNames]
    );

    $filter = new \Plugin\MobileApp\Services\NodeFilter();
    $authorizedAfter = \App\Services\ServerService::getAvailableServers($webUser);
    $reasonMap = [];
    foreach ([$vmess, $ws, $origin, $noUtls, $randomFp, $insecure, $tlsOnly, $tlsInsecure, $noHost, $encrypted] as $model) {
        foreach ($authorizedAfter as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $model->id) {
                $reasonMap[$model->name] = $filter->rejectReason($row);
            }
        }
    }
    check(
        'incompatible_authorized_nodes_have_independent_reject_reasons',
        ($reasonMap['vmess'] ?? null) === 'protocol_not_vless'
        && ($reasonMap['ws'] ?? null) === 'network_not_tcp'
        && ($reasonMap['origin'] ?? null) === 'flow_not_vision'
        && ($reasonMap['no-utls'] ?? null) === 'utls_disabled'
        && ($reasonMap['random'] ?? null) === 'fingerprint_random'
        && ($reasonMap['insecure'] ?? null) === 'allow_insecure'
        && ($reasonMap['tls-only'] ?? null) === 'security_not_reality'
        && ($reasonMap['tls-insecure'] ?? null) === 'allow_insecure'
        && ($reasonMap['no-host'] ?? null) === 'missing_host'
        && ($reasonMap['encrypted'] ?? null) === 'encryption_enabled',
        ['reasons' => $reasonMap]
    );

    $lookup = (new \Plugin\MobileApp\Adapters\NodeAdapter(
        app(\Plugin\MobileApp\Services\EntitlementService::class),
        $filter
    ))->findAuthorizedCompatible($webUser, \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $wrongGroup->id));
    check(
        'opaque_id_lookup_cannot_bypass_authorization_set',
        $lookup === null,
        ['lookup' => $lookup === null]
    );

    $report = json_encode($tests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $sink = \Plugin\MobileApp\Support\MobileLogRedactor::encodedSink();
    $leaked = false;
    foreach ($secrets as $secret) {
        if ($secret !== '' && (str_contains($report, $secret) || str_contains($sink, $secret))) {
            $leaked = true;
            break;
        }
    }
    check(
        'responses_logs_and_report_omit_plaintext_credentials',
        !$leaked && $sink !== '',
        ['leaked' => $leaked]
    );
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'line' => $exception->getLine(), 'msg' => $exception->getMessage()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-024',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
