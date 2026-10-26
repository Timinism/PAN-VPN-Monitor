<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Http;

use Closure;
use Illuminate\Http\Request;
use LibreNmsPlugin\PanosVpn\Panos\HttpClient;
use LibreNmsPlugin\PanosVpn\Panos\PanosException;
use LibreNmsPlugin\PanosVpn\Panos\Parser;
use LibreNmsPlugin\PanosVpn\Support\SettingsMerger;
use Symfony\Component\HttpFoundation\Response;

/**
 * LibreNMS saves plugin settings with its own POST route, plugin/settings/{plugin}.
 * Package routes are omitted when the route cache was built before the plugin was installed.
 */
class SettingsSync
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isSettingsPost($request) && $request->boolean('panos_vpn_test') && $this->allowed($request)) {
            session()->flash('panos_vpn_notice', self::testMessage($request, $this->storedSettings($request)));
        }

        $response = $next($request);

        if ($this->isSettingsPost($request) && ! $request->boolean('panos_vpn_test') && $request->exists('settings') && $response->isRedirection() && $this->allowed($request)) {
            session()->flash('panos_vpn_notice', 'Saved. The next poll uses these keys.');
        }

        return $response;
    }

    public static function normalize(object $plugin): void
    {
        if (($plugin->plugin_name ?? '') !== 'panos-vpn' || ! method_exists($plugin, 'isDirty') || ! $plugin->isDirty('settings')) {
            return;
        }

        $plugin->settings = SettingsMerger::merge(self::asArray($plugin->getOriginal('settings')), self::asArray($plugin->settings));
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function testMessage(Request $request, array $stored): string
    {
        $settings = SettingsMerger::merge([], $stored);
        $deviceId = (string) (int) $request->input('device_id');
        if (! class_exists(\App\Models\Device::class)) {
            return 'That device is not a PAN-OS firewall.';
        }

        $device = \App\Models\Device::query()->where('os', 'panos')->find($deviceId);
        if ($device === null) {
            return 'That device is not a PAN-OS firewall.';
        }

        $row = $settings['devices'][$deviceId] ?? [];
        $apiKey = trim((string) $request->input('api_key', ''));
        if ($apiKey === '') {
            $apiKey = (string) ($row['api_key'] ?? '');
        }
        $host = ($row['host'] ?? '') !== '' ? (string) $row['host'] : (string) $device->hostname;

        try {
            $client = new HttpClient(
                $host,
                $apiKey,
                (int) ($row['port'] ?? 443),
                (bool) ($settings['verify_tls'] ?? true),
                (int) ($settings['timeout'] ?? 20),
                ($row['target'] ?? '') !== '' ? (string) $row['target'] : null,
            );
            $tunnels = Parser::tunnels($client->op('<show><vpn><flow></flow></vpn></show>'));

            return $device->hostname.' answered show vpn flow with '.count($tunnels).' site-to-site tunnels.';
        } catch (PanosException $exception) {
            return $device->hostname.' — '.$exception->getMessage();
        }
    }

    private function isSettingsPost(Request $request): bool
    {
        if (! $request->isMethod('post') || $request->route() === null) {
            return false;
        }

        $plugin = $request->route('plugin');

        return is_object($plugin) && (string) ($plugin->plugin_name ?? '') === 'panos-vpn';
    }

    private function allowed(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->can('plugin.admin') || $user->can('admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSettings(Request $request): array
    {
        $plugin = $request->route('plugin');
        if (! is_object($plugin)) {
            return [];
        }

        return self::asArray($plugin->settings ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
