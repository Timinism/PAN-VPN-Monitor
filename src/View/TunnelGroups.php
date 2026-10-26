<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\View;

/**
 * PAN-OS lists one flow row per proxy ID. Rows that share a tunnel interface
 * are one site-to-site tunnel; the proxy IDs stay attached for the expand row.
 */
final class TunnelGroups
{
    /**
     * @param  list<array<string, mixed>>  $tunnels
     * @return list<array<string, mixed>>
     */
    public static function collapse(array $tunnels): array
    {
        $buckets = [];
        $order = [];
        foreach ($tunnels as $tunnel) {
            $interface = trim((string) ($tunnel['inner_if'] ?? ''));
            $key = $interface === ''
                ? 'solo:'.(int) ($tunnel['device_id'] ?? 0).':'.(string) ($tunnel['name'] ?? '')
                : 'if:'.(int) ($tunnel['device_id'] ?? 0).':'.$interface;
            if (! isset($buckets[$key])) {
                $order[] = $key;
                $buckets[$key] = [];
            }
            $buckets[$key][] = $tunnel;
        }

        $groups = [];
        foreach ($order as $key) {
            $members = $buckets[$key];
            if (count($members) < 2) {
                $only = $members[0];
                $only['proxy_ids'] = [];
                $only['shared_rate'] = ($only['bandwidth_scope'] ?? null) === 'interface';
                $groups[] = $only;
                continue;
            }
            $groups[] = self::parent($members);
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private static function parent(array $members): array
    {
        $interface = (string) ($members[0]['inner_if'] ?? '');
        $name = self::title($members, $interface);
        $proxies = [];
        foreach ($members as $member) {
            $proxies[] = $member + [
                'label' => self::label((string) ($member['name'] ?? ''), $name),
            ];
        }
        usort($proxies, static function (array $left, array $right): int {
            $leftUp = strtolower((string) ($left['state'] ?? '')) === 'active' ? 1 : 0;
            $rightUp = strtolower((string) ($right['state'] ?? '')) === 'active' ? 1 : 0;
            if ($leftUp !== $rightUp) {
                return $leftUp <=> $rightUp;
            }

            return strcasecmp((string) $left['label'], (string) $right['label']);
        });

        $sharedRate = true;
        foreach ($members as $member) {
            if (($member['bandwidth_scope'] ?? null) !== 'interface') {
                $sharedRate = false;
                break;
            }
        }
        $peer = self::same($members, 'peer_ip');
        $peerNote = null;
        if ($peer === null) {
            foreach ($members as $member) {
                if (trim((string) ($member['peer_ip'] ?? '')) !== '') {
                    $peerNote = 'multiple peers';
                    break;
                }
            }
        }

        return [
            'device_id' => (int) ($members[0]['device_id'] ?? 0),
            'device_name' => (string) ($members[0]['device_name'] ?? ''),
            'name' => $name,
            'pan_id' => null,
            'gateway' => self::same($members, 'gateway'),
            'local_ip' => self::same($members, 'local_ip'),
            'peer_ip' => $peer,
            'peer_note' => $peerNote,
            'inner_if' => $interface !== '' ? $interface : null,
            'outer_if' => self::same($members, 'outer_if'),
            'state' => self::state($members),
            'monitor' => self::same($members, 'monitor'),
            'monitor_status' => self::same($members, 'monitor_status'),
            'protocol' => self::same($members, 'protocol'),
            'encryption' => self::same($members, 'encryption'),
            'auth_hash' => self::same($members, 'auth_hash'),
            'encap_bytes' => self::total($members, 'encap_bytes'),
            'decap_bytes' => self::total($members, 'decap_bytes'),
            'bps_out' => self::total($members, 'bps_out'),
            'bps_in' => self::total($members, 'bps_in'),
            'bandwidth_scope' => $sharedRate ? 'interface' : 'tunnel',
            'shared_rate' => $sharedRate,
            'samples' => self::samples($members, $sharedRate),
            'proxy_ids' => $proxies,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private static function title(array $members, string $interface): string
    {
        $prefixes = [];
        foreach ($members as $member) {
            $name = (string) ($member['name'] ?? '');
            $colon = strpos($name, ':');
            if ($colon === false || $colon === 0) {
                $prefixes = [];
                break;
            }
            $prefixes[] = substr($name, 0, $colon);
        }
        if ($prefixes !== [] && count(array_unique($prefixes)) === 1) {
            return $prefixes[0];
        }
        $gateway = self::same($members, 'gateway');
        if ($gateway !== null) {
            return $gateway;
        }

        return $interface !== '' ? $interface : (string) ($members[0]['name'] ?? 'Tunnel');
    }

    private static function label(string $name, string $parent): string
    {
        $prefix = $parent.':';
        if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private static function state(array $members): string
    {
        $states = [];
        foreach ($members as $member) {
            $states[] = strtolower((string) ($member['state'] ?? 'unknown'));
        }
        $unique = array_values(array_unique($states));
        if (count($unique) === 1) {
            return $unique[0];
        }
        if (! in_array('active', $states, true)) {
            return 'inactive';
        }

        return 'partial';
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private static function same(array $members, string $field): ?string
    {
        $values = [];
        foreach ($members as $member) {
            $values[] = trim((string) ($member[$field] ?? ''));
        }
        $unique = array_values(array_unique($values));
        if (count($unique) === 1 && $unique[0] !== '') {
            return $unique[0];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private static function total(array $members, string $field): ?int
    {
        $total = null;
        $seen = [];
        foreach ($members as $member) {
            if (($member['bandwidth_scope'] ?? null) === 'interface') {
                $share = (int) ($member['device_id'] ?? 0).'|'.(string) ($member['inner_if'] ?? '');
                if (isset($seen[$share])) {
                    continue;
                }
                $seen[$share] = true;
            }
            $value = $member[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $total = ($total ?? 0) + (int) $value;
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private static function samples(array $members, bool $sharedRate): array
    {
        if ($sharedRate) {
            return $members[0]['samples'] ?? [];
        }
        $length = 0;
        foreach ($members as $member) {
            $length = max($length, count($member['samples'] ?? []));
        }
        $combined = [];
        for ($index = 0; $index < $length; $index++) {
            $in = null;
            $out = null;
            $seen = [];
            foreach ($members as $member) {
                $series = $member['samples'] ?? [];
                $sampleIndex = $index - ($length - count($series));
                if ($sampleIndex < 0) {
                    continue;
                }
                $sample = $series[$sampleIndex] ?? null;
                if (! is_array($sample)) {
                    continue;
                }
                if (($member['bandwidth_scope'] ?? null) === 'interface') {
                    $share = (int) ($member['device_id'] ?? 0).'|'.(string) ($member['inner_if'] ?? '');
                    if (isset($seen[$share])) {
                        continue;
                    }
                    $seen[$share] = true;
                }
                if (($sample['bps_in'] ?? null) !== null && $sample['bps_in'] !== '') {
                    $in = ($in ?? 0) + (int) $sample['bps_in'];
                }
                if (($sample['bps_out'] ?? null) !== null && $sample['bps_out'] !== '') {
                    $out = ($out ?? 0) + (int) $sample['bps_out'];
                }
            }
            $combined[] = ['bps_in' => $in, 'bps_out' => $out];
        }

        return $combined;
    }
}
