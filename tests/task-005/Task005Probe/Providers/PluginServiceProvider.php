<?php
namespace Plugin\Task005Probe\Providers;

class PluginServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void { $this->app->instance('task005.provider', true); }
}
