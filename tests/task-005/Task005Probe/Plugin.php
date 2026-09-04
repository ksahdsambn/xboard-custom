<?php
namespace Plugin\Task005Probe;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;

// No hooks or business mutations: only records lifecycle calls in process memory.
class Plugin extends AbstractPlugin
{
    public static array $calls = [];
    public function install(): void { self::$calls[] = 'install'; }
    public function boot(): void
    {
        self::$calls[] = 'boot';
        app()->instance('task005.config', $this->getConfig());
    }
    public function cleanup(): void { self::$calls[] = 'cleanup'; }
    public function update(string $oldVersion, string $newVersion): void
    {
        self::$calls[] = "update:$oldVersion:$newVersion";
    }
    public function schedule(Schedule $schedule): void
    {
        $schedule->command('task005:probe')->everyMinute()->name('task005-probe');
    }
}
