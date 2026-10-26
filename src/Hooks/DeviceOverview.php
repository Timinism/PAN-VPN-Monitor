<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Hooks;

use App\Models\Device;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNmsPlugin\PanosVpn\View\Pages;

class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(Authenticatable $user, Device $device): bool
    {
        return ($device->os ?? '') === 'panos' && $user->can('global-read');
    }

    public function handle(string $pluginName, Device $device): View
    {
        return view($pluginName.'::device-overview', [
            'panel_html' => Pages::device((int) $device->device_id),
        ]);
    }
}
