<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNmsPlugin\PanosVpn\Panos\HttpClient;
use LibreNMSPlugin\PanosVpn\Panos\PanosException;
use LibreNMSPlugin\PanosVpn\Panos\Parser;
use LibreNMSPlugin\PanosVpn\Support\SettingsMerger;

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
        $settings = SettingsMerger::merge([], $manager->getSettings('panos-vpn'));
        $deviceId = (string) (int) $request->input('device_id');
        $device = \App\Models\Device::query()->where('os', 'panos')->find($deviceId);
        if ($device === null) {
            return redirect(url('plugin/settings/panos-vpn'))->with('panos_vpn_notice', 'That device is not a PAN-OS firewall.');
        }

        $stored = $settings['devices'][$deviceId] ?? [];
        $apiKey = trim((string) $request->input('api_key', ''));
        if ($apiKey === '') {
            $apiKey = (string) ($stored['api_key'] ?? '');
        }
        $host = ($stored['host'] ?? '') !== '' ? (string) $stored['host'] : (string) $device->hostname;
        $postedHost = trim((string) $request->input('host', ''));
        if ($postedHost !== '') {
            $host = $postedHost;
        }

        try {
            $client = new HttpClient(
                $host,
                $apiKey,
                (int) ($stored['port'] ?? 443),
                (bool) ($settings['verify_tls'] ?? true),
                (int) ($settings['timeout'] ?? 20),
                ($stored['target'] ?? '') !== '' ? (string) $stored['target'] : null,
            );
            $tunnels = Parser::tunnels($client->op('<show><vpn><flow></flow></vpn></show>'));
            $message = $device->hostname.' answered show vpn flow with '.count($tunnels).' site-to-site tunnels.';
        } catch (PanosException $exception) {
            $message = $device->hostname.' — '.$exception->getMessage();
        }

        return redirect(url('plugin/settings/panos-vpn'))->with('panos_vpn_notice', $message);
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->can('plugin.admin') || $user->can('admin')), 403);
    }
}
