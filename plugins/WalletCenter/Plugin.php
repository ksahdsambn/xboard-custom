<?php

namespace Plugin\WalletCenter;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\WalletCenter\Services\AutoRenewService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // WalletCenter runtime hooks are registered by feature-specific services.
    }

    public function schedule(Schedule $schedule): void
    {
        if (!(bool) $this->getConfig('auto_renew_enabled', false)) {
            return;
        }

        $schedule->command(
            sprintf(
                'wallet-center:auto-renew-scan --limit=%d --due-only',
                AutoRenewService::DEFAULT_SCAN_LIMIT
            )
        )
            ->name('plugin:wallet-center:auto-renew-scan')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(AutoRenewService::SCHEDULE_OVERLAP_LOCK_MINUTES);
    }
}
