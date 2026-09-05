<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task025.sqlite',
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
        if (in_array($lower, ['sanctumtoken', 'token', 'password', 'authorization', 'uuid', 'publickey', 'shortid', 'privatekey', 'userid'], true)) {
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
            'public_key' => 'fixture-public-key-task025',
            'short_id' => 'cd34',
            'private_key' => 'fixture-private-key-must-never-leave-025',
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
    $server->host = $attrs['host'] ?? '203.0.113.25';
    $server->port = $attrs['port'] ?? '443';
    $server->server_port = 443;
    $server->group_ids = $attrs['group_ids'] ?? ['2501'];
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

    $password = 'task025-pass';
    $users = new \App\Services\UserService();
    $planA = makePlan('group-a', 2501);
    $planB = makePlan('group-b', 2502);
    $webUser = makeUser('web-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($webUser, $planA, 30);
    $webUser->save();
    $secrets[] = (string) $webUser->uuid;
    $otherUser = makeUser('other-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($otherUser, $planB, 30);
    $otherUser->save();
    $expiredUser = makeUser('exp-' . bin2hex(random_bytes(4)) . '@example.invalid', $password);
    $users->assignPlan($expiredUser, $planA, 10);
    $expiredUser->expired_at = time() - 60;
    $expiredUser->save();

    $compatible = makeServer('compatible', ['sort' => 1]);
    $dynamic = makeServer('dynamic', ['sort' => 2, 'port' => '25000-25010']);
    $hidden = makeServer('hidden', ['show' => false, 'sort' => 3]);
    $missingKey = makeServer('missing-key', ['sort' => 4, 'protocol_settings' => compatibleSettings(['reality_settings' => ['public_key' => '']])]);
    $vmess = makeServer('vmess', ['type' => 'vmess', 'sort' => 5, 'protocol_settings' => ['tls' => 1, 'network' => 'tcp']]);
    $otherNode = makeServer('other-group', ['group_ids' => ['2502'], 'sort' => 1, 'host' => '203.0.113.99']);
    $encrypted = makeServer('encrypted', ['sort' => 6, 'protocol_settings' => compatibleSettings(['encryption' => ['enabled' => true, 'decryption' => 'fixture-decryption-must-never-leave-025']])]);
    $secrets[] = 'fixture-public-key-task025';
    $secrets[] = 'fixture-private-key-must-never-leave-025';
    $secrets[] = 'fixture-decryption-must-never-leave-025';
    $secrets[] = (string) $compatible->host;

    $webToken = loginToken($webUser->email, $password);
    $webAuth = authHeaders($webToken);
    $opaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $compatible->id);
    $profileV1 = httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $webAuth);
    $profileV0 = httpRequest('GET', '/api/mobile/v0/profiles/' . $opaque, $webAuth);
    $bodyV1 = bodyOf($profileV1);
    $dto = $bodyV1['data'] ?? [];
    $authorized = \App\Services\ServerService::getAvailableServers($webUser);
    $source = null;
    foreach ($authorized as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $compatible->id) {
            $source = $row;
            break;
        }
    }
    $settings = is_array($source['protocol_settings'] ?? null) ? $source['protocol_settings'] : [];
    $reality = is_array($settings['reality_settings'] ?? null) ? $settings['reality_settings'] : [];
    check(
        'profiles_work_on_v0_and_v1',
        $profileV1->getStatusCode() === 200
        && $profileV0->getStatusCode() === 200
        && ($bodyV1['status'] ?? null) === 'success'
        && ($bodyV1['apiVersion'] ?? null) === 1
        && !array_key_exists('apiVersion', bodyOf($profileV0)),
        ['v0' => $profileV0->getStatusCode(), 'v1' => $profileV1->getStatusCode()]
    );
    check(
        'legal_profile_matches_authorized_node_sources',
        is_array($source)
        && ($dto['schemaVersion'] ?? null) === 1
        && ($dto['opaqueProfileId'] ?? null) === $opaque
        && ($dto['protocol'] ?? null) === 'vless'
        && ($dto['security'] ?? null) === 'reality'
        && ($dto['network'] ?? null) === 'tcp'
        && ($dto['flow'] ?? null) === 'xtls-rprx-vision'
        && ($dto['server'] ?? null) === $source['host']
        && (int) ($dto['port'] ?? 0) === (int) $source['port']
        && ($dto['userId'] ?? null) === $source['password']
        && ($dto['serverName'] ?? null) === ($reality['server_name'] ?? null)
        && ($dto['publicKey'] ?? null) === ($reality['public_key'] ?? null)
        && ($dto['shortId'] ?? null) === ($reality['short_id'] ?? null)
        && ($dto['fingerprint'] ?? null) === 'chrome'
        && ($dto['spiderX'] ?? null) === '/'
        && (int) ($dto['mtu'] ?? 0) === 1280
        && (int) ($dto['entitlementExpiresAtEpochMs'] ?? 0) === ((int) $webUser->expired_at * 1000),
        ['schema' => $dto['schemaVersion'] ?? null, 'mtu' => $dto['mtu'] ?? null]
    );
    check(
        'assembled_profile_matches_locked_schema_allowlist',
        ($dto['protocol'] ?? null) === 'vless'
        && ($dto['security'] ?? null) === 'reality'
        && ($dto['flow'] ?? null) === 'xtls-rprx-vision'
        && ($dto['fingerprint'] ?? null) === 'chrome'
        && ($dto['spiderX'] ?? null) === '/',
        ['allowlist' => true]
    );

    $encoded = json_encode($bodyV1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'profile_response_omits_private_key_and_raw_settings',
        !str_contains($encoded, 'fixture-private-key-must-never-leave-025')
        && !str_contains($encoded, 'protocol_settings')
        && !str_contains($encoded, 'privateKey')
        && !isset($dto['privateKey'], $dto['protocol_settings'], $dto['id']),
        ['keys' => array_keys(is_array($dto) ? $dto : [])]
    );

    $service = app(\Plugin\MobileApp\Services\ProfileService::class);
    $incomplete = [];
    foreach (['publicKey', 'server', 'userId', 'shortId', 'serverName'] as $field) {
        $copy = $dto;
        $copy[$field] = '';
        $incomplete[$field] = !$service->isComplete($copy);
    }
    $illegal = [];
    foreach ([['flow', 'xtls-rprx-origin'], ['network', 'ws'], ['fingerprint', 'random'], ['schemaVersion', 2], ['port', 0]] as [$field, $value]) {
        $copy = $dto;
        $copy[$field] = $value;
        $illegal[$field] = !$service->isComplete($copy);
    }
    check(
        'missing_or_illegal_required_fields_do_not_complete',
        !in_array(false, $incomplete, true) && !in_array(false, $illegal, true),
        ['missing' => array_keys($incomplete), 'illegal' => array_keys($illegal)]
    );

    $missingOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $missingKey->id);
    $missingBody = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $missingOpaque, $webAuth));
    $missingJson = json_encode($missingBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'missing_public_key_does_not_issue_credentials',
        ($missingBody['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE'
        && array_key_exists('data', $missingBody)
        && $missingBody['data'] === null
        && !str_contains($missingJson, (string) $webUser->uuid)
        && !str_contains($missingJson, 'fixture-public-key-task025'),
        ['code' => $missingBody['errorCode'] ?? null]
    );

    $schema2 = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $webAuth + ['HTTP_X_PROFILE_SCHEMA_VERSION' => '2']));
    check(
        'unsupported_schema_is_rejected',
        ($schema2['errorCode'] ?? null) === 'PROFILE_SCHEMA_UNSUPPORTED'
        && array_key_exists('data', $schema2)
        && $schema2['data'] === null,
        ['code' => $schema2['errorCode'] ?? null]
    );

    $otherOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $otherNode->id);
    $unauth = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $otherOpaque, $webAuth));
    check(
        'unauthorized_node_profile_is_rejected',
        ($unauth['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE',
        ['code' => $unauth['errorCode'] ?? null]
    );

    $hiddenOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $hidden->id);
    $hiddenBody = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $hiddenOpaque, $webAuth));
    $vmessOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $vmess->id);
    $vmessBody = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $vmessOpaque, $webAuth));
    $encryptedOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $encrypted->id);
    $encryptedBody = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $encryptedOpaque, $webAuth));
    $encryptedJson = json_encode($encryptedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check(
        'hidden_and_incompatible_nodes_are_rejected',
        ($hiddenBody['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE'
        && ($vmessBody['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE'
        && ($encryptedBody['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE'
        && array_key_exists('data', $encryptedBody)
        && $encryptedBody['data'] === null
        && !str_contains($encryptedJson, 'fixture-decryption-must-never-leave-025'),
        ['hidden' => $hiddenBody['errorCode'] ?? null, 'vmess' => $vmessBody['errorCode'] ?? null, 'encrypted' => $encryptedBody['errorCode'] ?? null]
    );

    $expiredToken = loginToken($expiredUser->email, $password);
    $expiredBody = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, authHeaders($expiredToken)));
    check(
        'expired_entitlement_cannot_get_profile',
        ($expiredBody['errorCode'] ?? null) === 'ENTITLEMENT_EXPIRED',
        ['code' => $expiredBody['errorCode'] ?? null]
    );

    $dynamicOpaque = \Plugin\MobileApp\Adapters\NodeAdapter::opaqueNodeId((int) $dynamic->id);
    $dynamicProfile = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $dynamicOpaque, $webAuth))['data'] ?? [];
    $dynamicPort = (int) ($dynamicProfile['port'] ?? -1);
    check(
        'dynamic_port_profile_uses_server_service_port',
        $dynamicPort >= 25000
        && $dynamicPort <= 25010
        && ($dynamicProfile['server'] ?? null) === '203.0.113.25',
        ['inRange' => $dynamicPort >= 25000 && $dynamicPort <= 25010]
    );

    $compatible->show = false;
    $compatible->save();
    $revoked = bodyOf(httpRequest('GET', '/api/mobile/v1/profiles/' . $opaque, $webAuth));
    check(
        'revoked_node_cannot_issue_cached_profile',
        ($revoked['errorCode'] ?? null) === 'PROFILE_UNAVAILABLE',
        ['code' => $revoked['errorCode'] ?? null]
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
    'taskId' => 'TASK-025',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'deviceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
