<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNmsPlugin\PanosVpn\View\Pages;

class Settings implements SettingsHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('plugin.admin');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        $notice = function_exists('session') ? session('panos_vpn_notice') : null;

        return [
            'content_view' => $pluginName.'::settings',
            'title' => 'Palo Alto VPN settings',
            'settings_html' => Pages::settings($settings, self::devices(), is_string($notice) ? $notice : null),
        ];
    }

    /**
     * @return list<array{device_id: int, hostname: string, display_name: string}>
     */
    private static function devices(): array
    {
        if (! class_exists(\App\Models\Device::class)) {
            return [];
        }

        return \App\Models\Device::query()
            ->where('os', 'panos')
            ->orderBy('hostname')
            ->get()
            ->map(static function ($device): array {
                $sysName = (string) ($device->sysName ?? '');

                return [
                    'device_id' => (int) $device->device_id,
                    'hostname' => (string) $device->hostname,
                    'display_name' => $sysName !== '' ? $sysName : (string) $device->hostname,
                ];
            })
            ->all();
    }
}
