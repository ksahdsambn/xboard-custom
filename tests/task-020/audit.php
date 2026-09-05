<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task020.sqlite',
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

function makeUser(string $email, bool $admin): array
{
    $user = new \App\Models\User();
    $user->email = $email;
    $user->password = password_hash('task020-pass', PASSWORD_BCRYPT);
    $user->uuid = substr(hash('sha256', $email), 0, 36);
    $user->token = substr(hash('sha256', $email . 'token'), 0, 32);
    $user->is_admin = $admin;
    $user->banned = false;
    $user->created_at = time();
    $user->updated_at = time();
    $user->save();
    return [$user, $user->createToken('mobile-test')->plainTextToken];
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

    $service = new \Plugin\MobileApp\Services\StartupConfigService();
    $service->settings();

    [$user, $userToken] = makeUser('user@example.invalid', false);
    [$admin, $adminToken] = makeUser('admin@example.invalid', true);
    $userAuth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $userToken];
    $adminAuth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken];
    $client = [
        'HTTP_X_APP_VERSION' => '1.0.0',
        'HTTP_X_ANDROID_API' => '26',
        'HTTP_X_LIBXRAY_VERSION' => 'lib-good',
        'HTTP_X_XRAY_CORE_VERSION' => 'core-good',
    ];

    $boot = httpRequest('GET', '/api/mobile/v1/bootstrap', $client);
    $bootJson = bodyOf($boot);
    $bootData = $bootJson['data'] ?? [];
    check(
        'default_bootstrap_is_normal_success',
        $boot->getStatusCode() === 200
        && ($bootJson['status'] ?? null) === 'success'
        && ($bootData['startupState'] ?? null) === 'normal'
        && ($bootData['mobileApiVersion'] ?? null) === 1
        && ($bootData['profileSchemaVersion'] ?? null) === 1
        && !array_key_exists('codePayload', $bootData)
        && !array_key_exists('privateKey', $bootData),
        ['state' => $bootData['startupState'] ?? null]
    );

    $v0 = bodyOf(httpRequest('GET', '/api/mobile/v0/bootstrap', $client));
    check('v0_bootstrap_succeeds_without_api_version_field', ($v0['status'] ?? null) === 'success' && !array_key_exists('apiVersion', $v0));

    $service->update(['maintenance' => true]);
    $maint = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', $client));
    $maintProfile = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    $maintBody = bodyOf($maintProfile);
    check(
        'maintenance_state_blocks_connect_with_service_maintenance',
        ($maint['data']['startupState'] ?? null) === 'maintenance'
        && $maintProfile->getStatusCode() === 503
        && ($maintBody['errorCode'] ?? null) === 'SERVICE_MAINTENANCE',
        [
            'bootstrap' => $maint['data']['startupState'] ?? null,
            'http' => $maintProfile->getStatusCode(),
            'code' => $maintBody['errorCode'] ?? null,
            'gate' => \Plugin\MobileApp\Http\Middleware\MobileStartupGate::$invocations,
        ]
    );

    $privacy = httpRequest('GET', '/api/mobile/v1/legal/privacy', $client);
    check(
        'maintenance_still_allows_legal',
        $privacy->getStatusCode() === 200 && (bodyOf($privacy)['data']['version'] ?? null) === 'privacy-v1'
    );

    $service->update(['maintenance' => false, 'regionUnavailable' => true]);
    $region = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', $client));
    $regionProfile = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    check(
        'region_unavailable_has_unique_machine_code',
        ($region['data']['startupState'] ?? null) === 'region_unavailable'
        && (bodyOf($regionProfile)['errorCode'] ?? null) === 'REGION_UNAVAILABLE'
    );

    $service->update(['regionUnavailable' => false, 'suggestedAppVersion' => '2.0.0']);
    $suggest = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', $client));
    $suggestProfile = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    check(
        'suggest_upgrade_allows_connect_to_reach_controller',
        ($suggest['data']['startupState'] ?? null) === 'suggest_upgrade'
        && $suggestProfile->getStatusCode() === 501
        && (bodyOf($suggestProfile)['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
    );

    $ordinary = httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, [
        'forceUpgradeEnabled' => true,
        'forceUpgradeReason' => 'ordinary-feature-update',
        'forceUpgradeEvidenceRef' => 'ticket-1',
        'forceUpgradeApprovedBy' => 'security',
    ]);
    $ordinaryDirect = false;
    try {
        $service->update([
            'forceUpgradeEnabled' => true,
            'forceUpgradeReason' => 'ordinary-feature-update',
            'forceUpgradeEvidenceRef' => 'ticket-1',
            'forceUpgradeApprovedBy' => 'security',
        ]);
    } catch (\Plugin\MobileApp\Exceptions\MobileApiException $exception) {
        $ordinaryDirect = $exception->errorCode === 'AUTH_FORBIDDEN';
    }
    check(
        'ordinary_feature_update_cannot_enable_force_upgrade',
        $ordinaryDirect
        && $ordinary->getStatusCode() === 403
        && (bodyOf($ordinary)['errorCode'] ?? null) === 'AUTH_FORBIDDEN',
        ['http' => $ordinary->getStatusCode(), 'body' => bodyOf($ordinary), 'direct' => $ordinaryDirect]
    );

    $missingEvidence = httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, [
        'forceUpgradeEnabled' => true,
        'forceUpgradeReason' => 'security-vulnerability',
        'forceUpgradeEvidenceRef' => '',
        'forceUpgradeApprovedBy' => 'security',
    ]);
    check(
        'force_upgrade_requires_evidence_and_approval',
        $missingEvidence->getStatusCode() === 403
    );

    $allowedReasons = ['security-vulnerability', 'platform-hard-requirement', 'proven-incompatibility'];
    $reasonFailed = [];
    foreach ($allowedReasons as $reason) {
        $resp = httpRequest('PUT', '/api/mobile/v1/admin/compat', $adminAuth, [
            'forceUpgradeEnabled' => true,
            'forceUpgradeReason' => $reason,
            'forceUpgradeEvidenceRef' => 'advisory-1',
            'forceUpgradeApprovedBy' => 'security-owner',
            'suggestedAppVersion' => '1.0.0',
        ]);
        if ($resp->getStatusCode() !== 200 || (bodyOf($resp)['data']['updated'] ?? null) !== true) {
            $reasonFailed[] = $reason;
        }
    }
    check('approved_force_upgrade_reasons_accepted', $reasonFailed === [], ['failed' => $reasonFailed]);

    $forceBoot = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', $client));
    $forceProfile = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    $forceLegal = httpRequest('GET', '/api/mobile/v1/legal/terms');
    $forceDelete = httpRequest('POST', '/api/mobile/v1/account/deletion/preview', $userAuth);
    $forceSupport = httpRequest('GET', '/api/mobile/v1/legal/support');
    check(
        'force_upgrade_blocks_connect_but_keeps_legal_and_deletion',
        ($forceBoot['data']['startupState'] ?? null) === 'force_upgrade'
        && (bodyOf($forceProfile)['errorCode'] ?? null) === 'FORCE_UPGRADE'
        && $forceLegal->getStatusCode() === 200
        && $forceSupport->getStatusCode() === 200
        && $forceDelete->getStatusCode() === 501
        && (bodyOf($forceDelete)['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
    );

    $service->update(['maintenance' => true]);
    $preserved = $service->settings();
    check(
        'partial_update_preserves_force_upgrade',
        (bool) $preserved->force_upgrade_enabled === true
        && (bool) $preserved->maintenance === true
        && (string) $preserved->force_upgrade_reason === 'proven-incompatibility',
        ['force' => (bool) $preserved->force_upgrade_enabled, 'reason' => $preserved->force_upgrade_reason]
    );

    $service->update([
        'forceUpgradeEnabled' => false,
        'force_upgrade_enabled' => false,
        'maintenance' => false,
        'minimumAppVersion' => '9.0.0',
        'suggestedAppVersion' => '9.0.0',
    ]);
    $oldApp = bodyOf(httpRequest('GET', '/api/mobile/v1/bootstrap', $client));
    $oldProfile = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    check(
        'below_minimum_app_is_force_upgrade_with_app_version_unsupported',
        ($oldApp['data']['startupState'] ?? null) === 'force_upgrade'
        && (bodyOf($oldProfile)['errorCode'] ?? null) === 'APP_VERSION_UNSUPPORTED'
    );

    $service->update([
        'minimumAppVersion' => '1.0.0',
        'suggestedAppVersion' => '1.0.0',
        'disabledKernelVersions' => [
            ['libxray' => 'lib-bad', 'xrayCore' => 'core-bad'],
        ],
        'purchaseEnabled' => true,
        'forceUpgradeEnabled' => false,
    ]);
    $badKernel = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + [
        'HTTP_X_APP_VERSION' => '1.0.0',
        'HTTP_X_LIBXRAY_VERSION' => 'lib-bad',
        'HTTP_X_XRAY_CORE_VERSION' => 'core-bad',
    ]);
    $goodKernel = httpRequest('GET', '/api/mobile/v1/profiles/n1', $userAuth + $client);
    check(
        'disabling_one_kernel_pair_does_not_affect_other_versions',
        (bodyOf($badKernel)['errorCode'] ?? null) === 'KERNEL_VERSION_DISABLED'
        && $goodKernel->getStatusCode() === 501
        && (bodyOf($goodKernel)['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
    );

    $service->update(['purchaseEnabled' => false, 'disabledKernelVersions' => []]);
    $pausedAccount = httpRequest('GET', '/api/mobile/v1/account', $userAuth + $client);
    $pausedPurchase = httpRequest('POST', '/api/mobile/v1/play/purchases', $userAuth + $client, ['token' => 'ignored']);
    check(
        'purchase_pause_still_allows_account_view',
        $pausedAccount->getStatusCode() === 501
        && (bodyOf($pausedAccount)['errorCode'] ?? null) === 'OPERATION_NOT_IMPLEMENTED'
        && (bodyOf($pausedPurchase)['errorCode'] ?? null) === 'PURCHASE_INVALID'
    );

    $offline = \Plugin\MobileApp\Services\StartupConfigService::clientOfflineDecision();
    check(
        'offline_is_client_local_and_unique',
        $offline['startupState'] === 'offline'
        && in_array('legal.privacy.get', $offline['allowedOperations'], true)
        && in_array('profiles.get', $offline['blockedOperations'], true)
    );

    $encoded = json_encode($bootData) . json_encode(bodyOf($privacy));
    check(
        'remote_config_and_legal_have_no_code_or_secrets',
        !str_contains($encoded, 'codePayload')
        && !str_contains($encoded, 'privateKey')
        && !str_contains($encoded, '<script')
    );

    $audits = \Plugin\MobileApp\Models\CompatAudit::query()->count();
    check('compat_updates_write_audit_records', $audits >= 1, ['count' => $audits]);
} catch (\Throwable $exception) {
    check('audit_completed_without_exception', false, ['type' => $exception::class, 'line' => $exception->getLine()]);
}

$passed = count($tests) > 0 && count(array_filter($tests, fn ($item) => $item['passed'] !== true)) === 0;
echo json_encode([
    'schemaVersion' => 1,
    'taskId' => 'TASK-020',
    'status' => $passed ? 'passed' : 'failed',
    'evidenceClass' => 'non-production-simulation',
    'formalAcceptanceClaimed' => false,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
