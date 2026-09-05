<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Plugin\MobileApp\Commands\HealthCommand;

$root = dirname(__DIR__, 3);
if (!is_file($root . '/bootstrap/app.php')) {
    $root = dirname(__DIR__, 2);
}
if (!is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "MobileApp post-deploy: Laravel root not found\n");
    exit(1);
}

chdir($root);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$code = 'mobile_app';
$manager = new PluginManager();

try {
    $configFile = $root . '/plugins/MobileApp/config.json';
    if (!is_file($configFile)) {
        throw new RuntimeException('MobileApp config.json missing after overlay sync');
    }
    $config = json_decode((string) file_get_contents($configFile), true) ?: [];
    $installed = Plugin::query()->where('code', $code)->first();
    if (!$installed) {
        $manager->install($code);
    } else {
        $newVersion = (string) ($config['version'] ?? '');
        if ($newVersion !== '' && version_compare($newVersion, (string) $installed->version, '>')) {
            $manager->update($code);
        }
    }
    $manager->enable($code);
    $app->make(Kernel::class)->registerCommand(new HealthCommand());
    $health = Artisan::call('mobile-app:health');
    echo Artisan::output();
    if ($health !== 0) {
        fwrite(STDERR, "MobileApp health check failed; overlay deploy aborted\n");
        exit(1);
    }
    fwrite(STDOUT, "MobileApp post-deploy ok\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'MobileApp post-deploy failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
