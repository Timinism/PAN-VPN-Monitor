<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

use LibreNmsPlugin\PanosVpn\Panos\Snapshot;
use LibreNmsPlugin\PanosVpn\View\TunnelGroups;

final class Repository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function save(
        int $deviceId,
        string $hostname,
        string $displayName,
        Snapshot $snapshot,
        int $now,
        int $pollMs,
        int $retentionDays = 14,
        ?int $sampleCap = null,
    ): void {
        $this->connection->transaction(function () use ($deviceId, $hostname, $displayName, $snapshot, $now, $pollMs, $retentionDays, $sampleCap): void {
            $warning = $snapshot->warnings === [] ? null : implode(' ', $snapshot->warnings);
            $this->upsertFirewall($deviceId, $hostname, $displayName, $now, null, $warning, $pollMs);
            $this->saveTunnels($deviceId, $snapshot->tunnels, $now, $sampleCap);
            $this->saveGateways($deviceId, $snapshot->gateways, $now, $sampleCap);
            $cutoff = $now - max(1, $retentionDays) * 86400;
            $this->connection->exec('DELETE FROM panos_vpn_tunnel_samples WHERE polled_at < ?', [$cutoff]);
            $this->connection->exec('DELETE FROM panos_vpn_gateway_samples WHERE polled_at < ?', [$cutoff]);
        });
    }

    public function fail(int $deviceId, string $hostname, string $displayName, string $error, int $now): void
    {
        $existing = $this->connection->one('SELECT device_id FROM panos_vpn_firewalls WHERE device_id = ?', [$deviceId]);
        if ($existing === null) {
            $this->connection->exec(
                'INSERT INTO panos_vpn_firewalls (device_id, hostname, display_name, last_polled_at, last_error, last_warning, poll_ms) VALUES (?, ?, ?, ?, ?, NULL, NULL)',
                [$deviceId, $hostname, $displayName, $now, $error]
            );

            return;
        }

        $this->connection->exec(
            'UPDATE panos_vpn_firewalls SET hostname = ?, display_name = ?, last_polled_at = ?, last_error = ? WHERE device_id = ?',
            [$hostname, $displayName, $now, $error, $deviceId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'firewalls' => $this->connection->all('SELECT * FROM panos_vpn_firewalls ORDER BY display_name'),
            'tunnels' => $this->connection->all('SELECT * FROM panos_vpn_tunnels ORDER BY name'),
            'tunnel_samples' => $this->connection->all('SELECT * FROM panos_vpn_tunnel_samples ORDER BY polled_at'),
            'gateways' => $this->connection->all('SELECT * FROM panos_vpn_gateways ORDER BY name'),
            'gateway_samples' => $this->connection->all('SELECT * FROM panos_vpn_gateway_samples ORDER BY polled_at'),
            'users' => $this->connection->all('SELECT * FROM panos_vpn_users ORDER BY gateway_name, username'),
        ];
    }

    /**
     * @return array{down: int, tunnels: int, users: int}
     */
    public function summary(): array
    {
        $tunnels = TunnelGroups::collapse(
            $this->connection->all('SELECT device_id, name, state, inner_if, gateway FROM panos_vpn_tunnels')
        );
        $down = 0;
        foreach ($tunnels as $tunnel) {
            if (strtolower((string) $tunnel['state']) !== 'active') {
                $down++;
            }
        }
        $users = $this->connection->one('SELECT COALESCE(SUM(current_users), 0) AS users FROM panos_vpn_gateways');

        return [
            'down' => $down,
            'tunnels' => count($tunnels),
            'users' => (int) ($users['users'] ?? 0),
        ];
    }

    public function latestPoll(): ?int
    {
        $row = $this->connection->one('SELECT MAX(last_polled_at) AS latest FROM panos_vpn_firewalls');
        if ($row === null || $row['latest'] === null) {
            return null;
        }

        return (int) $row['latest'];
    }

    /**
     * @param  list<array<string, mixed>>  $tunnels
     */
    private function saveTunnels(int $deviceId, array $tunnels, int $now, ?int $sampleCap): void
    {
        $seen = [];
        foreach ($tunnels as $tunnel) {
            $name = (string) $tunnel['name'];
            $seen[$name] = true;
            $existing = $this->connection->one(
                'SELECT id, encap_bytes, decap_bytes, last_seen_at FROM panos_vpn_tunnels WHERE device_id = ? AND name = ?',
                [$deviceId, $name]
            );
            $bpsOut = null;
            $bpsIn = null;
            if ($existing !== null) {
                $elapsed = $now - (int) $existing['last_seen_at'];
                $bpsOut = Rate::bps($existing['encap_bytes'], $tunnel['encap_bytes'], $elapsed);
                $bpsIn = Rate::bps($existing['decap_bytes'], $tunnel['decap_bytes'], $elapsed);
                $this->connection->exec(
                    'UPDATE panos_vpn_tunnels SET pan_id = ?, gateway = ?, local_ip = ?, peer_ip = ?, inner_if = ?, outer_if = ?, state = ?, monitor = ?, monitor_status = ?, protocol = ?, encryption = ?, auth_hash = ?, encap_bytes = ?, decap_bytes = ?, bps_out = ?, bps_in = ?, bandwidth_scope = ?, last_seen_at = ? WHERE id = ?',
                    [
                        (string) $tunnel['pan_id'],
                        $tunnel['gateway'],
                        $tunnel['local_ip'],
                        $tunnel['peer_ip'],
                        $tunnel['inner_if'],
                        $tunnel['outer_if'],
                        (string) $tunnel['state'],
                        $tunnel['monitor'],
                        $tunnel['monitor_status'],
                        $tunnel['protocol'],
                        $tunnel['encryption'],
                        $tunnel['auth_hash'],
                        $tunnel['encap_bytes'],
                        $tunnel['decap_bytes'],
                        $bpsOut,
                        $bpsIn,
                        $tunnel['bandwidth_scope'],
                        $now,
                        (int) $existing['id'],
                    ]
                );
                $rowId = (int) $existing['id'];
            } else {
                $this->connection->exec(
                    'INSERT INTO panos_vpn_tunnels (device_id, pan_id, name, gateway, local_ip, peer_ip, inner_if, outer_if, state, monitor, monitor_status, protocol, encryption, auth_hash, encap_bytes, decap_bytes, bps_out, bps_in, bandwidth_scope, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
                    [
                        $deviceId,
                        (string) $tunnel['pan_id'],
                        $name,
                        $tunnel['gateway'],
                        $tunnel['local_ip'],
                        $tunnel['peer_ip'],
                        $tunnel['inner_if'],
                        $tunnel['outer_if'],
                        (string) $tunnel['state'],
                        $tunnel['monitor'],
                        $tunnel['monitor_status'],
                        $tunnel['protocol'],
                        $tunnel['encryption'],
                        $tunnel['auth_hash'],
                        $tunnel['encap_bytes'],
                        $tunnel['decap_bytes'],
                        $tunnel['bandwidth_scope'],
                        $now,
                    ]
                );
                $rowId = $this->connection->lastInsertId();
            }

            if ($bpsIn !== null || $bpsOut !== null) {
                $this->connection->exec(
                    'INSERT INTO panos_vpn_tunnel_samples (tunnel_row, polled_at, bps_in, bps_out) VALUES (?, ?, ?, ?)',
                    [$rowId, $now, $bpsIn, $bpsOut]
                );
                $this->capSamples('panos_vpn_tunnel_samples', 'tunnel_row', $rowId, $sampleCap);
            }
        }

        $current = $this->connection->all('SELECT id, name FROM panos_vpn_tunnels WHERE device_id = ?', [$deviceId]);
        foreach ($current as $row) {
            if (isset($seen[(string) $row['name']])) {
                continue;
            }
            $this->connection->exec('DELETE FROM panos_vpn_tunnel_samples WHERE tunnel_row = ?', [(int) $row['id']]);
            $this->connection->exec('DELETE FROM panos_vpn_tunnels WHERE id = ?', [(int) $row['id']]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $gateways
     */
    private function saveGateways(int $deviceId, array $gateways, int $now, ?int $sampleCap): void
    {
        $this->connection->exec('DELETE FROM panos_vpn_users WHERE device_id = ?', [$deviceId]);
        $seen = [];
        foreach ($gateways as $gateway) {
            $name = (string) $gateway['name'];
            $seen[$name] = true;
            $users = (int) ($gateway['current_users'] ?? count($gateway['users'] ?? []));
            $existing = $this->connection->one(
                'SELECT id FROM panos_vpn_gateways WHERE device_id = ? AND name = ?',
                [$deviceId, $name]
            );
            if ($existing !== null) {
                $this->connection->exec(
                    'UPDATE panos_vpn_gateways SET current_users = ?, previous_users = ?, max_users = ?, last_seen_at = ? WHERE id = ?',
                    [$users, $gateway['previous_users'], $gateway['max_users'], $now, (int) $existing['id']]
                );
                $rowId = (int) $existing['id'];
            } else {
                $this->connection->exec(
                    'INSERT INTO panos_vpn_gateways (device_id, name, current_users, previous_users, max_users, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$deviceId, $name, $users, $gateway['previous_users'], $gateway['max_users'], $now]
                );
                $rowId = $this->connection->lastInsertId();
            }
            $this->connection->exec(
                'INSERT INTO panos_vpn_gateway_samples (gateway_row, polled_at, current_users) VALUES (?, ?, ?)',
                [$rowId, $now, $users]
            );
            $this->capSamples('panos_vpn_gateway_samples', 'gateway_row', $rowId, $sampleCap);

            foreach ($gateway['users'] ?? [] as $user) {
                $this->connection->exec(
                    'INSERT INTO panos_vpn_users (device_id, gateway_name, username, domain, computer, client, virtual_ip, public_ip, tunnel_type, login_time, login_time_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $deviceId,
                        $name,
                        (string) $user['username'],
                        $user['domain'] ?? null,
                        $user['computer'] ?? null,
                        $user['client'] ?? null,
                        $user['virtual_ip'] ?? null,
                        $user['public_ip'] ?? null,
                        $user['tunnel_type'] ?? null,
                        $user['login_time'] ?? null,
                        $user['login_time_utc'] ?? null,
                    ]
                );
            }
        }

        $current = $this->connection->all('SELECT id, name FROM panos_vpn_gateways WHERE device_id = ?', [$deviceId]);
        foreach ($current as $row) {
            if (isset($seen[(string) $row['name']])) {
                continue;
            }
            $this->connection->exec('DELETE FROM panos_vpn_gateway_samples WHERE gateway_row = ?', [(int) $row['id']]);
            $this->connection->exec('DELETE FROM panos_vpn_gateways WHERE id = ?', [(int) $row['id']]);
        }
    }

    private function upsertFirewall(int $deviceId, string $hostname, string $displayName, int $now, ?string $error, ?string $warning, ?int $pollMs): void
    {
        $existing = $this->connection->one('SELECT device_id FROM panos_vpn_firewalls WHERE device_id = ?', [$deviceId]);
        if ($existing === null) {
            $this->connection->exec(
                'INSERT INTO panos_vpn_firewalls (device_id, hostname, display_name, last_polled_at, last_error, last_warning, poll_ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$deviceId, $hostname, $displayName, $now, $error, $warning, $pollMs]
            );

            return;
        }
        $this->connection->exec(
            'UPDATE panos_vpn_firewalls SET hostname = ?, display_name = ?, last_polled_at = ?, last_error = ?, last_warning = ?, poll_ms = ? WHERE device_id = ?',
            [$hostname, $displayName, $now, $error, $warning, $pollMs, $deviceId]
        );
    }

    private function capSamples(string $table, string $column, int $rowId, ?int $sampleCap): void
    {
        if ($sampleCap === null || $sampleCap < 2) {
            return;
        }
        $rows = $this->connection->all("SELECT id FROM {$table} WHERE {$column} = ? ORDER BY polled_at DESC", [$rowId]);
        if (count($rows) <= $sampleCap) {
            return;
        }
        $drop = [];
        foreach (array_slice($rows, $sampleCap) as $row) {
            $drop[] = (int) $row['id'];
        }
        $placeholders = implode(',', array_fill(0, count($drop), '?'));
        $this->connection->exec("DELETE FROM {$table} WHERE id IN ({$placeholders})", $drop);
    }
}
