<?php
declare(strict_types=1);

chdir('/audit');
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/task017.sqlite',
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
$app = require '/audit/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.stores.redis' => ['driver' => 'array']]);
\Illuminate\Support\Facades\Cache::forgetDriver('redis');

function statusOf(string $method, string $path): int
{
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $request = \Illuminate\Http\Request::create($path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response->getStatusCode();
}

echo json_encode([
    'bootstrap' => statusOf('GET', '/api/mobile/v1/bootstrap'),
    'account' => statusOf('GET', '/api/mobile/v1/account'),
    'login' => statusOf('POST', '/api/v1/passport/auth/login'),
], JSON_UNESCAPED_SLASHES);
