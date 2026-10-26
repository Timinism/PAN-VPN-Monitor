<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn;

use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNmsPlugin\PanosVpn\Console\PollCommand;
use LibreNmsPlugin\PanosVpn\Hooks\DeviceOverview;
use LibreNmsPlugin\PanosVpn\Hooks\MenuEntry;
use LibreNmsPlugin\PanosVpn\Hooks\Page;
use LibreNmsPlugin\PanosVpn\Hooks\Settings;
use LibreNmsPlugin\PanosVpn\Listeners\PollAfterDevice;

class PanosVpnServiceProvider extends ServiceProvider
{
    public function boot(PluginManagerInterface $pluginManager): void
    {
        $pluginName = 'panos-vpn';

        $pluginManager->publishHook($pluginName, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook($pluginName, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($pluginName, DeviceOverviewHook::class, DeviceOverview::class);
        $pluginManager->publishHook($pluginName, SettingsHook::class, Settings::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([PollCommand::class]);
        }

        if (! $pluginManager->pluginEnabled($pluginName)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', $pluginName);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (class_exists(\App\Events\DevicePolled::class)) {
            $this->app['events']->listen(\App\Events\DevicePolled::class, PollAfterDevice::class);
        }
    }
}
