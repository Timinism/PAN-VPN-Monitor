<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Support;

final class SettingsMerger
{
    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function merge(array $existing, array $incoming): array
    {
        $devices = self::devices(is_array($existing['devices'] ?? null) ? $existing['devices'] : []);
        $posted = is_array($incoming['devices'] ?? null) ? $incoming['devices'] : [];

        foreach ($posted as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) (int) $id;
            if ($key === '0' && (string) $id !== '0' && (string) $id !== '0.0') {
                continue;
            }
            $previous = $devices[$key]['api_key'] ?? '';
            $submitted = trim((string) ($row['api_key'] ?? ''));
            if (! empty($row['clear_key'])) {
                $apiKey = '';
            } elseif ($submitted === '' || $submitted === '********') {
                $apiKey = $previous;
            } else {
                $apiKey = $submitted;
            }

            $devices[$key] = [
                'enabled' => self::truthy($row['enabled'] ?? false),
                'api_key' => $apiKey,
                'host' => self::host((string) ($row['host'] ?? '')),
                'port' => self::port($row['port'] ?? 443),
                'target' => self::token((string) ($row['target'] ?? '')),
            ];
        }

        return [
            'verify_tls' => self::truthy($incoming['verify_tls'] ?? ($existing['verify_tls'] ?? true)),
            'timeout' => self::bound($incoming['timeout'] ?? ($existing['timeout'] ?? 20), 3, 120),
            'retention_days' => self::bound($incoming['retention_days'] ?? ($existing['retention_days'] ?? 14), 1, 90),
            'per_tunnel_counters' => self::truthy($incoming['per_tunnel_counters'] ?? ($existing['per_tunnel_counters'] ?? true)),
            'devices' => $devices,
        ];
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, array{enabled: bool, api_key: string, host: string, port: int, target: string}>
     */
    private static function devices(array $rows): array
    {
        $devices = [];
        foreach ($rows as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) (int) $id;
            $devices[$key] = [
                'enabled' => self::truthy($row['enabled'] ?? false),
                'api_key' => (string) ($row['api_key'] ?? ''),
                'host' => self::host((string) ($row['host'] ?? '')),
                'port' => self::port($row['port'] ?? 443),
                'target' => self::token((string) ($row['target'] ?? '')),
            ];
        }

        return $devices;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    private static function bound(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private static function port(mixed $value): int
    {
        return self::bound($value, 1, 65535);
    }

    private static function host(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strpbrk($value, " \t/?#@") !== false) {
            return '';
        }

        return $value;
    }

    private static function token(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && ! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return '';
        }

        return $value;
    }
}
