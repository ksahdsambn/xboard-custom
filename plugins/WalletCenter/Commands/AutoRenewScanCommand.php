<?php

namespace Plugin\WalletCenter\Commands;

use Illuminate\Console\Command;
use Plugin\WalletCenter\Services\AutoRenewService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;

class AutoRenewScanCommand extends Command
{
    protected $signature = 'wallet-center:auto-renew-scan {--limit=100} {--due-only}';

    protected $description = 'Scan WalletCenter auto renew settings and renew due subscriptions';

    public function handle(
        WalletCenterConfigService $configService,
        AutoRenewService $autoRenewService
    ): int {
        if (!$configService->isPluginEnabled()) {
            $this->line('WalletCenter plugin is disabled.');

            return self::SUCCESS;
        }

        if (!$configService->isFeatureEnabled('auto_renew')) {
            $this->line('WalletCenter auto renew feature is disabled.');

            return self::SUCCESS;
        }

        $summary = $autoRenewService->scan(
            (int) $this->option('limit'),
            (bool) $this->option('due-only')
        );

        $this->info('WalletCenter auto renew scan completed.');
        $this->line('Scanned settings: ' . $summary['scanned']);
        $this->line('Successful renewals: ' . $summary['success']);
        $this->line('Failed renewals: ' . $summary['failed']);
        $this->line('Skipped renewals: ' . $summary['skipped']);
        $this->line('Not due yet: ' . $summary['not_due']);
        $this->line('Processed user IDs: ' . implode(',', $summary['processed_user_ids']));

        return self::SUCCESS;
    }
}
