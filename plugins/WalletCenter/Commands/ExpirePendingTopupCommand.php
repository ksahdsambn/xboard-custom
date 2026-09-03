<?php

namespace Plugin\WalletCenter\Commands;

use Illuminate\Console\Command;
use Plugin\WalletCenter\Services\TopupService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;

class ExpirePendingTopupCommand extends Command
{
    protected $signature = 'wallet-center:expire-pending-topup {--minutes=120}';

    protected $description = 'Expire stale WalletCenter topup orders that never received a gateway callback';

    public function handle(
        WalletCenterConfigService $configService,
        TopupService $topupService
    ): int {
        if (!$configService->isPluginEnabled()) {
            $this->line('WalletCenter plugin is disabled.');

            return self::SUCCESS;
        }

        $minutes = (int) ($this->option('minutes') ?: $configService->getConfig()['topup_expire_minutes'] ?? 120);
        $expired = $topupService->expireStalePending($minutes);

        $this->info('Expired stale topup orders: ' . $expired);

        return self::SUCCESS;
    }
}
