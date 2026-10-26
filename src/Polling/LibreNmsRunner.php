<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

use LibreNmsPlugin\PanosVpn\Panos\HttpClient;
use LibreNmsPlugin\PanosVpn\Support\SettingsMerger;

final class LibreNmsRunner
{
    public function __construct(private readonly Engine $engine = new Engine())
    {
    }

    /**
     * @return list<string>
     */
    public function pollSpec(?string $spec = null): array
    {
        $settings = $this->settings();
        $lines = [];
        foreach ($this->devices($spec) as $device) {
            $lines[] = $this->pollDevice($device, $settings, false);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function pollDevice(object $device, ?array $settings = null, bool $respectInterval = true): string
    {
        $settings ??= $this->settings();
        $deviceId = (int) $device->device_id;
        $hostname = (string) $device->hostname;
        $display = (string) (($device->sysName ?? '') !== '' ? $device->sysName : $hostname);
        $config = $settings['devices'][(string) $deviceId] ?? null;
        if (! is_array($config) || empty($config['enabled']) || ($config['api_key'] ?? '') === '') {
            return $display.' skipped, no API key';
        }

        if ($respectInterval) {
            try {
                $row = (new LaravelConnection())->one('SELECT last_polled_at, last_error FROM panos_vpn_firewalls WHERE device_id = ?', [$deviceId]);
            } catch (\Throwable) {
                $row = null;
            }
            if ($row !== null && $row['last_polled_at'] !== null && time() - (int) $row['last_polled_at'] < 60 && empty($row['last_error'])) {
                return $display.' skipped, polled within the last minute';
            }
        }

        $host = ($config['host'] ?? '') !== '' ? (string) $config['host'] : $hostname;
        $client = new HttpClient(
            $host,
            (string) $config['api_key'],
            (int) ($config['port'] ?? 443),
            (bool) ($settings['verify_tls'] ?? true),
            (int) ($settings['timeout'] ?? 20),
            ($config['target'] ?? '') !== '' ? (string) $config['target'] : null,
        );

        $this->engine->poll(
            $client,
            new Repository(new LaravelConnection()),
            $deviceId,
            $hostname,
            $display,
            time(),
            (bool) ($settings['per_tunnel_counters'] ?? true),
            (int) ($settings['retention_days'] ?? 14),
        );

        try {
            $stored = (new LaravelConnection())->one('SELECT last_error, last_warning FROM panos_vpn_firewalls WHERE device_id = ?', [$deviceId]);
        } catch (\Throwable) {
            return $display.' polled';
        }
        if (! empty($stored['last_error'])) {
            return $display.' failed: '.$stored['last_error'];
        }
        $summary = '';
        if (! empty($stored['last_warning'])) {
            $summary = ' ('.$stored['last_warning'].')';
        }

        return $display.' polled'.$summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $stored = [];
        if (function_exists('app')) {
            try {
                $stored = app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)->getSettings('panos-vpn');
            } catch (\Throwable) {
                $stored = [];
            }
        }

        return SettingsMerger::merge(is_array($stored) ? $stored : [], is_array($stored) ? $stored : []);
    }

    /**
     * @return list<object>
     */
    private function devices(?string $spec): array
    {
        $query = \App\Models\Device::query()->where('os', 'panos')->orderBy('hostname');
        if ($spec !== null && $spec !== '') {
            $query->where(function ($inner) use ($spec): void {
                if (ctype_digit($spec)) {
                    $inner->where('device_id', (int) $spec);
                }
                $inner->orWhere('hostname', 'like', str_replace('*', '%', $spec));
            });
        }

        return $query->get()->all();
    }
}
