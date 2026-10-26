<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Panos;

final class Poller
{
    private const DETAIL_LIMIT = 30;

    public function collect(Client $client, bool $perTunnelCounters = true): Snapshot
    {
        $flowXml = $client->op('<show><vpn><flow></flow></vpn></show>');
        $tunnels = Parser::tunnels($flowXml);
        $warnings = [];

        try {
            $sa = Parser::ipsecSa($client->op('<show><vpn><ipsec-sa></ipsec-sa></vpn></show>'));
            foreach ($tunnels as &$tunnel) {
                $extra = $sa[$tunnel['name']] ?? null;
                if ($extra === null) {
                    continue;
                }
                $tunnel['gateway'] = $tunnel['gateway'] ?: $extra['gateway'];
                $tunnel['protocol'] = $extra['protocol'];
                $tunnel['encryption'] = $extra['encryption'];
                $tunnel['auth_hash'] = $extra['auth_hash'];
            }
            unset($tunnel);
        } catch (PanosException $exception) {
            $warnings[] = 'IPsec SA lookup failed: '.$exception->getMessage();
        }

        $this->fillCounters($client, $tunnels, $perTunnelCounters, $warnings);

        $gateways = $this->gateways($client, $warnings);

        return new Snapshot(array_values($tunnels), $gateways, $warnings);
    }

    /**
     * Per-proxy-ID counters when several tunnels share one interface. Otherwise one
     * interface counter call covers the tunnel, which is the bandwidth operators see.
     *
     * @param  list<array<string, mixed>>  $tunnels
     * @param  list<string>  $warnings
     */
    private function fillCounters(Client $client, array &$tunnels, bool $perTunnelCounters, array &$warnings): void
    {
        $groups = [];
        foreach ($tunnels as $index => $tunnel) {
            if ($tunnel['bandwidth_scope'] === 'tunnel') {
                continue;
            }
            $interface = (string) ($tunnel['inner_if'] ?? '');
            $groups[$interface][] = $index;
        }

        $detailCalls = 0;
        $detailBroken = false;
        foreach ($groups as $interface => $indexes) {
            if ($perTunnelCounters && count($indexes) > 1 && ! $detailBroken) {
                foreach ($indexes as $index) {
                    if ($detailCalls >= self::DETAIL_LIMIT) {
                        break;
                    }
                    $tunnel = $tunnels[$index];
                    if ($tunnel['pan_id'] === '' || ! ctype_digit((string) $tunnel['pan_id'])) {
                        continue;
                    }
                    try {
                        $bytes = Parser::flowBytes($client->op(
                            '<show><vpn><flow><tunnel-id>'.$tunnel['pan_id'].'</tunnel-id></flow></vpn></show>'
                        ));
                        $detailCalls++;
                        if ($bytes['encap'] !== null || $bytes['decap'] !== null) {
                            $tunnels[$index]['encap_bytes'] = $bytes['encap'];
                            $tunnels[$index]['decap_bytes'] = $bytes['decap'];
                            $tunnels[$index]['bandwidth_scope'] = 'tunnel';
                        }
                    } catch (PanosException $exception) {
                        $warnings[] = 'Per-tunnel counters failed: '.$exception->getMessage();
                        $detailBroken = true;
                        break;
                    }
                }
            }

            if ($interface === '' || ! preg_match('/^[A-Za-z0-9_.:\/-]+$/', (string) $interface)) {
                continue;
            }
            $remaining = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => $tunnels[$index]['bandwidth_scope'] !== 'tunnel'
            ));
            if ($remaining === []) {
                continue;
            }
            try {
                $safe = htmlspecialchars((string) $interface, ENT_XML1);
                $bytes = Parser::interfaceBytes($client->op('<show><interface>'.$safe.'</interface></show>'));
            } catch (PanosException $exception) {
                $warnings[] = 'Interface '.$interface.' counters failed: '.$exception->getMessage();
                continue;
            }
            if ($bytes['encap'] === null && $bytes['decap'] === null) {
                continue;
            }
            foreach ($remaining as $index) {
                $tunnels[$index]['encap_bytes'] = $bytes['encap'];
                $tunnels[$index]['decap_bytes'] = $bytes['decap'];
                $tunnels[$index]['bandwidth_scope'] = 'interface';
            }
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function gateways(Client $client, array &$warnings): array
    {
        $gateways = [];

        try {
            foreach (Parser::gatewayStats($client->op('<show><global-protect-gateway><statistics></statistics></global-protect-gateway></show>')) as $row) {
                $gateways[$row['name']] = $row + ['users' => []];
            }
        } catch (PanosException $exception) {
            $warnings[] = 'GlobalProtect statistics failed: '.$exception->getMessage();
        }

        try {
            foreach (Parser::gatewayStats($client->op('<show><global-protect-gateway><gateway></gateway></global-protect-gateway></show>')) as $row) {
                if (! isset($gateways[$row['name']])) {
                    $gateways[$row['name']] = $row + ['users' => []];
                    continue;
                }
                if ($row['max_users'] !== null) {
                    $gateways[$row['name']]['max_users'] = $row['max_users'];
                }
                if ($gateways[$row['name']]['current_users'] === null && $row['current_users'] !== null) {
                    $gateways[$row['name']]['current_users'] = $row['current_users'];
                }
            }
        } catch (PanosException) {
            // Gateway inventory is optional when statistics already named the gateways.
        }

        try {
            $users = Parser::users($client->op('<show><global-protect-gateway><current-user></current-user></global-protect-gateway></show>'));
            $tagged = 0;
            foreach ($users as $user) {
                if (! empty($user['gateway'])) {
                    $tagged++;
                }
            }
            $split = $users !== [] && $tagged === 0 && count($gateways) > 1;
            if (! $split) {
                $this->assignUsers($gateways, $users);
            } else {
                foreach (array_keys($gateways) as $name) {
                    $safe = htmlspecialchars((string) $name, ENT_XML1);
                    $perGateway = Parser::users(
                        $client->op('<show><global-protect-gateway><current-user><gateway>'.$safe.'</gateway></current-user></global-protect-gateway></show>'),
                        (string) $name
                    );
                    $this->assignUsers($gateways, $perGateway);
                }
            }
        } catch (PanosException $exception) {
            $warnings[] = 'GlobalProtect user list failed: '.$exception->getMessage();
        }

        foreach ($gateways as &$gateway) {
            if ($gateway['current_users'] === null) {
                $gateway['current_users'] = count($gateway['users']);
            }
        }
        unset($gateway);

        return array_values($gateways);
    }

    /**
     * @param  array<string, array<string, mixed>>  $gateways
     * @param  list<array<string, mixed>>  $users
     */
    private function assignUsers(array &$gateways, array $users): void
    {
        $only = count($gateways) === 1 ? (string) array_key_first($gateways) : null;
        foreach ($users as $user) {
            $name = $user['gateway'] ?: $only ?: 'Unassigned';
            unset($user['gateway']);
            if (! isset($gateways[$name])) {
                $gateways[$name] = [
                    'name' => $name,
                    'current_users' => null,
                    'previous_users' => null,
                    'max_users' => null,
                    'users' => [],
                ];
            }
            $gateways[$name]['users'][] = $user;
        }
    }
}
