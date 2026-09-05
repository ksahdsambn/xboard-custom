<?php

namespace Plugin\MobileApp;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public static array $lifecycle = [];

    public function install(): void
    {
        self::$lifecycle[] = 'install';
    }

    public function boot(): void
    {
        self::$lifecycle[] = 'boot';
        app()->instance('mobile_app.plugin', $this);
    }

    public function cleanup(): void
    {
        self::$lifecycle[] = 'cleanup';
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        self::$lifecycle[] = "update:$oldVersion:$newVersion";
    }
}
