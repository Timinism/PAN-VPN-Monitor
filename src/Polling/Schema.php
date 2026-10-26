<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

use PDO;

final class Schema
{
    public static function install(PDO $pdo): void
    {
        foreach (self::statements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS panos_vpn_firewalls (
                device_id INTEGER PRIMARY KEY,
                hostname TEXT NOT NULL,
                display_name TEXT NOT NULL,
                last_polled_at INTEGER,
                last_error TEXT,
                last_warning TEXT,
                poll_ms INTEGER
            )',
            'CREATE TABLE IF NOT EXISTS panos_vpn_tunnels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                pan_id TEXT NOT NULL DEFAULT \'\',
                name TEXT NOT NULL,
                gateway TEXT,
                local_ip TEXT,
                peer_ip TEXT,
                inner_if TEXT,
                outer_if TEXT,
                state TEXT NOT NULL,
                monitor TEXT,
                monitor_status TEXT,
                protocol TEXT,
                encryption TEXT,
                auth_hash TEXT,
                encap_bytes INTEGER,
                decap_bytes INTEGER,
                bps_out INTEGER,
                bps_in INTEGER,
                bandwidth_scope TEXT,
                last_seen_at INTEGER,
                UNIQUE (device_id, name)
            )',
            'CREATE TABLE IF NOT EXISTS panos_vpn_tunnel_samples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tunnel_row INTEGER NOT NULL,
                polled_at INTEGER NOT NULL,
                bps_in INTEGER,
                bps_out INTEGER
            )',
            'CREATE TABLE IF NOT EXISTS panos_vpn_gateways (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                current_users INTEGER NOT NULL DEFAULT 0,
                previous_users INTEGER,
                max_users INTEGER,
                last_seen_at INTEGER,
                UNIQUE (device_id, name)
            )',
            'CREATE TABLE IF NOT EXISTS panos_vpn_gateway_samples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gateway_row INTEGER NOT NULL,
                polled_at INTEGER NOT NULL,
                current_users INTEGER NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS panos_vpn_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                gateway_name TEXT NOT NULL,
                username TEXT NOT NULL,
                domain TEXT,
                computer TEXT,
                client TEXT,
                virtual_ip TEXT,
                public_ip TEXT,
                tunnel_type TEXT,
                login_time TEXT,
                login_time_utc INTEGER
            )',
            'CREATE INDEX IF NOT EXISTS panos_vpn_tunnel_samples_lookup ON panos_vpn_tunnel_samples (tunnel_row, polled_at)',
            'CREATE INDEX IF NOT EXISTS panos_vpn_gateway_samples_lookup ON panos_vpn_gateway_samples (gateway_row, polled_at)',
            'CREATE INDEX IF NOT EXISTS panos_vpn_users_device ON panos_vpn_users (device_id, gateway_name)',
        ];
    }
}
