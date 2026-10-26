<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNmsPlugin\PanosVpn\Http\SettingsSync;
use LibreNmsPlugin\PanosVpn\Support\SettingsMerger;

class SettingsController extends Controller
{
    public function update(Request $request, PluginManagerInterface $manager): RedirectResponse
    {
        $this->guard();
        $existing = $manager->getSettings('panos-vpn');
        $incoming = $request->input('settings', []);
        $manager->setSettings('panos-vpn', SettingsMerger::merge(
            is_array($existing) ? $existing : [],
            is_array($incoming) ? $incoming : [],
        ));

        return redirect(url('plugin/settings/panos-vpn'))->with('panos_vpn_notice', 'Saved.');
    }

    public function test(Request $request, PluginManagerInterface $manager): RedirectResponse
    {
        $this->guard();
        $stored = $manager->getSettings('panos-vpn');
        $message = SettingsSync::testMessage($request, is_array($stored) ? $stored : []);

        return redirect(url('plugin/settings/panos-vpn'))->with('panos_vpn_notice', $message);
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->can('plugin.admin') || $user->can('admin')), 403);
    }
}
