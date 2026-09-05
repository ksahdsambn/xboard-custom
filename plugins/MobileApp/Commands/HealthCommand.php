<?php

namespace Plugin\MobileApp\Commands;

use Illuminate\Console\Command;
use Plugin\MobileApp\Support\PluginStatus;

class HealthCommand extends Command
{
    protected $signature = 'mobile-app:health';

    protected $description = 'Report MobileApp plugin enablement without exposing secrets.';

    public function handle(): int
    {
        $this->line(PluginStatus::isEnabled() ? 'enabled' : 'disabled');
        return self::SUCCESS;
    }
}
