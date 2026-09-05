<?php

namespace Plugin\MobileApp\Commands;

use Illuminate\Console\Command;
use Plugin\MobileApp\Services\RtdnService;
use Plugin\MobileApp\Support\PluginStatus;

class RtdnRetryCommand extends Command
{
    protected $signature = 'mobile-app:rtdn-retry';

    protected $description = 'Retry due RTDN Developer API rechecks without exposing secrets.';

    public function handle(): int
    {
        if (!PluginStatus::isEnabled()) {
            $this->line('disabled');
            return self::SUCCESS;
        }
        $count = RtdnService::make()->processDueRetries();
        $this->line((string) $count);
        return self::SUCCESS;
    }
}
