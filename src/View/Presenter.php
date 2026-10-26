<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\View;

final class Presenter
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function dashboard(array $data, array $query, string $basePath, string $timezone, bool $lab, int $now): array
    {
        $firewallId = isset($query['fw']) && $query['fw'] !== '' ? (int) $query['fw'] : null;
        $state = (string) ($query['state'] ?? 'all');
        if (! in_array($state, ['all', 'up', 'down'], true)) {
            $state = 'all';
        }
        $gatewayFilter = trim((string) ($query['gw'] ?? ''));
        $search = trim((string) ($query['q'] ?? ''));
        $needle = strtolower($search);

        $samples = self::groupSamples($data['tunnel_samples'] ?? [], 'tunnel_row');
        $gatewaySamples = self::groupSamples($data['gateway_samples'] ?? [], 'gateway_row');
        $names = [];
        foreach ($data['firewalls'] ?? [] as $firewall) {
            $names[(int) $firewall['device_id']] = (string) ($firewall['display_name'] ?: $firewall['hostname']);
        }

        $tunnels = [];
        foreach ($data['tunnels'] ?? [] as $tunnel) {
            $deviceId = (int) $tunnel['device_id'];
            $history = array_slice($samples[(int) $tunnel['id']] ?? [], -36);
            $tunnels[] = $tunnel + [
                'device_name' => $names[$deviceId] ?? ('Device '.$deviceId),
                'samples' => $history,
            ];
        }

        $usersByGateway = [];
        foreach ($data['users'] ?? [] as $user) {
            $key = (int) $user['device_id'].'|'.(string) $user['gateway_name'];
            $usersByGateway[$key][] = $user;
        }

        $gateways = [];
        foreach ($data['gateways'] ?? [] as $gateway) {
            $deviceId = (int) $gateway['device_id'];
            $key = $deviceId.'|'.(string) $gateway['name'];
            $gateways[] = $gateway + [
                'device_name' => $names[$deviceId] ?? ('Device '.$deviceId),
                'samples' => array_slice($gatewaySamples[(int) $gateway['id']] ?? [], -36),
                'users' => $usersByGateway[$key] ?? [],
            ];
        }

        $tunnels = TunnelGroups::collapse($tunnels);
        $scopedTunnels = array_values(array_filter(
            $tunnels,
            static fn (array $tunnel): bool => $firewallId === null || (int) $tunnel['device_id'] === $firewallId
        ));
        $scopedGateways = array_values(array_filter(
            $gateways,
            static fn (array $gateway): bool => $firewallId === null || (int) $gateway['device_id'] === $firewallId
        ));

        $visibleTunnels = array_values(array_filter($scopedTunnels, static function (array $tunnel) use ($state, $needle): bool {
            $up = strtolower((string) $tunnel['state']) === 'active';
            if ($state === 'up' && ! $up) {
                return false;
            }
            if ($state === 'down' && $up) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $proxyNames = [];
            foreach ($tunnel['proxy_ids'] ?? [] as $proxy) {
                $proxyNames[] = (string) ($proxy['label'] ?? '');
                $proxyNames[] = (string) ($proxy['name'] ?? '');
            }
            $haystack = strtolower(implode(' ', [
                (string) $tunnel['name'],
                (string) $tunnel['device_name'],
                (string) ($tunnel['peer_ip'] ?? ''),
                (string) ($tunnel['gateway'] ?? ''),
                (string) ($tunnel['inner_if'] ?? ''),
                implode(' ', $proxyNames),
            ]));

            return str_contains($haystack, $needle);
        }));

        usort($visibleTunnels, static function (array $left, array $right): int {
            $leftDown = strtolower((string) $left['state']) === 'active' ? 1 : 0;
            $rightDown = strtolower((string) $right['state']) === 'active' ? 1 : 0;
            if ($leftDown !== $rightDown) {
                return $leftDown <=> $rightDown;
            }

            return [$left['device_name'], $left['name']] <=> [$right['device_name'], $right['name']];
        });

        $visibleGateways = array_values(array_filter($scopedGateways, static function (array $gateway) use ($gatewayFilter, $needle): bool {
            if ($gatewayFilter !== '' && (string) $gateway['name'] !== $gatewayFilter) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            if (str_contains(strtolower((string) $gateway['name'].' '.$gateway['device_name']), $needle)) {
                return true;
            }
            foreach ($gateway['users'] as $user) {
                $haystack = strtolower(implode(' ', [
                    (string) $user['username'],
                    (string) ($user['computer'] ?? ''),
                    (string) ($user['public_ip'] ?? ''),
                    (string) ($user['virtual_ip'] ?? ''),
                ]));
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        }));

        $firewalls = [];
        foreach ($data['firewalls'] ?? [] as $firewall) {
            $id = (int) $firewall['device_id'];
            $ownTunnels = array_values(array_filter($tunnels, static fn (array $tunnel): bool => (int) $tunnel['device_id'] === $id));
            $ownGateways = array_values(array_filter($gateways, static fn (array $gateway): bool => (int) $gateway['device_id'] === $id));
            $rates = self::rates($ownTunnels);
            $up = 0;
            foreach ($ownTunnels as $tunnel) {
                if (strtolower((string) $tunnel['state']) === 'active') {
                    $up++;
                }
            }
            $gpUsers = 0;
            foreach ($ownGateways as $gateway) {
                $gpUsers += (int) $gateway['current_users'];
            }
            $firewalls[] = [
                'device_id' => $id,
                'hostname' => (string) $firewall['hostname'],
                'display_name' => (string) ($firewall['display_name'] ?: $firewall['hostname']),
                'last_polled_at' => self::intOrNull($firewall['last_polled_at'] ?? null),
                'last_error' => $firewall['last_error'] ?? null,
                'last_warning' => $firewall['last_warning'] ?? null,
                'tunnel_up' => $up,
                'tunnel_total' => count($ownTunnels),
                'bps_in' => $rates['in'],
                'bps_out' => $rates['out'],
                'gp_users' => $gpUsers,
                'active' => $firewallId === $id,
            ];
        }

        $totals = self::rates($scopedTunnels);
        $up = 0;
        foreach ($scopedTunnels as $tunnel) {
            if (strtolower((string) $tunnel['state']) === 'active') {
                $up++;
            }
        }
        $gpUsers = 0;
        foreach ($scopedGateways as $gateway) {
            $gpUsers += (int) $gateway['current_users'];
        }
        $errors = 0;
        foreach ($firewalls as $firewall) {
            if ($firewallId !== null && $firewall['device_id'] !== $firewallId) {
                continue;
            }
            if (! empty($firewall['last_error'])) {
                $errors++;
            }
        }

        return [
            'lab' => $lab,
            'base' => $basePath,
            'timezone' => $timezone,
            'now' => $now,
            'filters' => [
                'fw' => $firewallId,
                'state' => $state,
                'gw' => $gatewayFilter,
                'q' => $search,
            ],
            'stats' => [
                ['label' => 'Tunnels up', 'value' => (string) $up, 'hint' => 'of '.count($scopedTunnels).' site-to-site', 'tone' => 'up'],
                ['label' => 'Tunnels down', 'value' => (string) (count($scopedTunnels) - $up), 'hint' => 'partial, inactive, or init', 'tone' => $up === count($scopedTunnels) ? 'muted' : 'down'],
                ['label' => 'Traffic in', 'value' => Format::rate($totals['in']), 'hint' => 'decap, toward the firewall', 'tone' => 'in'],
                ['label' => 'Traffic out', 'value' => Format::rate($totals['out']), 'hint' => 'encap, toward the peer', 'tone' => 'out'],
                ['label' => 'GlobalProtect', 'value' => (string) $gpUsers, 'hint' => count($scopedGateways).' gateway'.(count($scopedGateways) === 1 ? '' : 's'), 'tone' => 'users'],
            ],
            'firewall_errors' => $errors,
            'firewalls' => $firewalls,
            'tunnels' => $visibleTunnels,
            'tunnel_total' => count($scopedTunnels),
            'gateways' => $visibleGateways,
            'gateway_choices' => $scopedGateways,
            'configured' => $firewalls !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private static function groupSamples(array $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row[$key]][] = $row;
        }

        return $grouped;
    }

    /**
     * Interface-scoped counters are shared by every proxy-ID on that tunnel interface,
     * so they are counted once per firewall and interface.
     *
     * @param  list<array<string, mixed>>  $tunnels
     * @return array{in: ?int, out: ?int}
     */
    public static function rates(array $tunnels): array
    {
        $in = null;
        $out = null;
        $shared = [];
        foreach ($tunnels as $tunnel) {
            if (($tunnel['bandwidth_scope'] ?? null) === 'interface') {
                $shareKey = (int) $tunnel['device_id'].'|'.(string) ($tunnel['inner_if'] ?? '');
                if (isset($shared[$shareKey])) {
                    continue;
                }
                $shared[$shareKey] = true;
            }
            if ($tunnel['bps_in'] !== null && $tunnel['bps_in'] !== '') {
                $in = ($in ?? 0) + (int) $tunnel['bps_in'];
            }
            if ($tunnel['bps_out'] !== null && $tunnel['bps_out'] !== '') {
                $out = ($out ?? 0) + (int) $tunnel['bps_out'];
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
