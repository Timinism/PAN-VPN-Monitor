<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Panos;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class Parser
{
    public static function assertOk(string $xml): void
    {
        $root = self::document($xml)->documentElement;
        if ($root === null) {
            throw new PanosException('PAN-OS API returned an empty document');
        }
        if (strtolower($root->getAttribute('status')) === 'error') {
            $message = '';
            $nodes = (new DOMXPath(self::document($xml)))->query('//*[local-name()="msg" or local-name()="message"]');
            if ($nodes !== false && $nodes->length > 0) {
                $message = trim($nodes->item(0)->textContent);
            }
            throw new PanosException($message !== '' ? $message : 'PAN-OS API returned an error');
        }
    }

    /**
     * Site-to-site flows from `show vpn flow`.
     *
     * @return list<array<string, mixed>>
     */
    public static function tunnels(string $xml): array
    {
        self::assertOk($xml);
        $xpath = self::xpath($xml);
        $nodes = self::elements($xpath->query('//*[local-name()="IPSec"]/*[local-name()="entry"]'));
        if ($nodes === []) {
            $nodes = self::elements($xpath->query(
                '//*[local-name()="entry"][child::*[local-name()="state"] and (child::*[local-name()="peerip"] or child::*[local-name()="peer-ip"] or child::*[local-name()="inner-if"] or child::*[local-name()="innerif"])]'
            ));
        }

        $tunnels = [];
        $seen = [];
        foreach ($nodes as $node) {
            $name = self::text($node, 'name');
            $id = self::text($node, 'id');
            if ($name === '' && $id === '') {
                continue;
            }
            if ($name === '') {
                $name = 'tunnel-'.$id;
            }
            if (isset($seen[$name])) {
                $name .= '#'.$id;
            }
            $seen[$name] = true;

            $encap = self::firstInt($node, ['encap-bytes', 'encapbytes', 'encap_bytes', 'obytes']);
            $decap = self::firstInt($node, ['decap-bytes', 'decapbytes', 'decap_bytes', 'ibytes']);

            $tunnels[] = [
                'pan_id' => $id,
                'name' => $name,
                'gateway' => self::nullIfEmpty(self::firstText($node, ['gateway', 'gw'])),
                'local_ip' => self::nullIfEmpty(self::firstText($node, ['localip', 'local-ip'])),
                'peer_ip' => self::nullIfEmpty(self::firstText($node, ['peerip', 'peer-ip'])),
                'inner_if' => self::nullIfEmpty(self::firstText($node, ['inner-if', 'innerif', 'tunnel-if'])),
                'outer_if' => self::nullIfEmpty(self::firstText($node, ['outer-if', 'outerif'])),
                'state' => strtolower(self::text($node, 'state') ?: 'unknown'),
                'monitor' => self::nullIfEmpty(strtolower((string) (self::firstText($node, ['mon', 'monitor']) ?? ''))),
                'monitor_status' => self::nullIfEmpty(strtolower((string) (self::firstText($node, ['mon-status', 'monitor-status', 'monstatus']) ?? ''))),
                'protocol' => null,
                'encryption' => null,
                'auth_hash' => null,
                'encap_bytes' => $encap,
                'decap_bytes' => $decap,
                'bandwidth_scope' => ($encap !== null || $decap !== null) ? 'tunnel' : null,
            ];
        }

        return $tunnels;
    }

    /**
     * @return array<string, array{gateway: ?string, protocol: ?string, encryption: ?string, auth_hash: ?string}>
     */
    public static function ipsecSa(string $xml): array
    {
        self::assertOk($xml);
        $xpath = self::xpath($xml);
        $nodes = self::elements($xpath->query('//*[local-name()="entries"]/*[local-name()="entry"]'));
        if ($nodes === []) {
            $nodes = self::elements($xpath->query('//*[local-name()="entry"][child::*[local-name()="i_spi"] or child::*[local-name()="proto"]]'));
        }

        $byName = [];
        foreach ($nodes as $node) {
            $name = self::text($node, 'name');
            if ($name === '') {
                continue;
            }
            $byName[$name] = [
                'gateway' => self::nullIfEmpty(self::text($node, 'gateway')),
                'protocol' => self::nullIfEmpty(self::firstText($node, ['proto', 'protocol'])),
                'encryption' => self::nullIfEmpty(self::firstText($node, ['enc', 'encryption'])),
                'auth_hash' => self::nullIfEmpty(self::firstText($node, ['hash', 'auth'])),
            ];
        }

        return $byName;
    }

    /**
     * Byte counters from `show vpn flow tunnel-id`.
     *
     * @return array{encap: ?int, decap: ?int}
     */
    public static function flowBytes(string $xml): array
    {
        self::assertOk($xml);
        $document = self::document($xml);

        return [
            'encap' => self::searchInt($document, ['encap-bytes', 'encapbytes', 'encap_bytes']),
            'decap' => self::searchInt($document, ['decap-bytes', 'decapbytes', 'decap_bytes']),
        ];
    }

    /**
     * Tunnel-interface counters. Received bytes are decap (in) and transmitted bytes are encap (out).
     *
     * @return array{encap: ?int, decap: ?int}
     */
    public static function interfaceBytes(string $xml): array
    {
        self::assertOk($xml);
        $document = self::document($xml);
        $encap = self::searchInt($document, ['obytes', 'bytes-transmitted', 'bytes_transmitted']);
        $decap = self::searchInt($document, ['ibytes', 'bytes-received', 'bytes_received']);

        $xpath = new DOMXPath($document);
        foreach (self::elements($xpath->query('//*[local-name()="entry"]')) as $entry) {
            $label = strtolower(self::text($entry, 'name'));
            $value = self::firstInt($entry, ['value']);
            if ($value === null) {
                continue;
            }
            if ($label === 'bytes transmitted') {
                $encap = $value;
            }
            if ($label === 'bytes received') {
                $decap = $value;
            }
        }

        return ['encap' => $encap, 'decap' => $decap];
    }

    /**
     * GlobalProtect gateway rows from statistics or `show global-protect-gateway gateway`.
     *
     * @return list<array{name: string, current_users: ?int, previous_users: ?int, max_users: ?int}>
     */
    public static function gatewayStats(string $xml): array
    {
        self::assertOk($xml);
        $xpath = self::xpath($xml);
        $nodes = self::elements($xpath->query('//*[local-name()="entry" or local-name()="gateway"]'));
        $rows = [];
        foreach ($nodes as $node) {
            if (self::text($node, 'username') !== '') {
                continue;
            }
            $current = self::firstInt($node, ['current-users', 'current-user', 'CurrentUsers', 'TotalCurrentUsers', 'currentusers']);
            $previous = self::firstInt($node, ['previous-users', 'previous-user', 'PreviousUsers', 'TotalPreviousUsers']);
            $max = self::firstInt($node, ['max-users', 'max-user', 'MaxUsers', 'maxusers']);
            $name = self::gatewayName($node);
            if ($name === '' || ($current === null && $previous === null && $max === null && self::text($node, 'name') === '' && $node->getAttribute('name') === '')) {
                continue;
            }
            if ($name === '') {
                continue;
            }
            if ($current === null && $previous === null && $max === null) {
                continue;
            }
            $rows[$name] = [
                'name' => $name,
                'current_users' => $current,
                'previous_users' => $previous,
                'max_users' => $max,
            ];
        }

        if ($rows === []) {
            $result = self::elements($xpath->query('//*[local-name()="result"]'));
            if ($result !== []) {
                $node = $result[0];
                $name = self::gatewayName($node);
                $current = self::firstInt($node, ['current-users', 'current-user', 'CurrentUsers', 'TotalCurrentUsers']);
                if ($name !== '' && $current !== null) {
                    $rows[$name] = [
                        'name' => $name,
                        'current_users' => $current,
                        'previous_users' => self::firstInt($node, ['previous-users', 'previous-user', 'PreviousUsers']),
                        'max_users' => self::firstInt($node, ['max-users', 'max-user', 'MaxUsers']),
                    ];
                }
            }
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function users(string $xml, ?string $defaultGateway = null): array
    {
        self::assertOk($xml);
        $xpath = self::xpath($xml);
        $nodes = self::elements($xpath->query('//*[local-name()="entry"][child::*[local-name()="username"]]'));
        $users = [];
        foreach ($nodes as $node) {
            $username = self::text($node, 'username');
            if ($username === '') {
                continue;
            }
            $gateway = self::firstText($node, ['gateway', 'gateway-name']);
            if ($gateway === null || $gateway === '') {
                $gateway = self::ancestorGateway($node) ?? $defaultGateway;
            }
            $login = self::firstInt($node, ['login-time-utc']);
            $users[] = [
                'gateway' => self::nullIfEmpty($gateway),
                'username' => $username,
                'domain' => self::nullIfEmpty(self::text($node, 'domain')),
                'computer' => self::nullIfEmpty(self::text($node, 'computer')),
                'client' => self::nullIfEmpty(self::text($node, 'client')),
                'virtual_ip' => self::nullIfEmpty(self::firstText($node, ['virtual-ip', 'private-ip'])),
                'public_ip' => self::nullIfEmpty(self::firstText($node, ['public-ip', 'client-ip'])),
                'tunnel_type' => self::nullIfEmpty(self::firstText($node, ['tunnel-type', 'vpn-type'])),
                'login_time' => self::nullIfEmpty(self::text($node, 'login-time')),
                'login_time_utc' => $login,
            ];
        }

        return $users;
    }

    private static function ancestorGateway(DOMElement $node): ?string
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMElement) {
            $local = strtolower($parent->localName ?? '');
            if ($local === 'gateway') {
                $name = $parent->getAttribute('name');
                if ($name === '') {
                    $name = self::text($parent, 'name');
                }

                return $name !== '' ? $name : null;
            }
            if ($local === 'entry' && self::text($parent, 'username') === '') {
                $name = $parent->getAttribute('name');
                if ($name === '') {
                    $name = self::firstText($parent, ['gateway', 'gateway-name', 'name']) ?? '';
                }
                if ($name !== '') {
                    return $name;
                }
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private static function gatewayName(DOMElement $node): string
    {
        $name = self::firstText($node, ['gateway', 'gateway-name', 'name']) ?? '';
        if ($name === '') {
            $name = $node->getAttribute('name');
        }
        if ($name === '' && ! in_array(strtolower($node->localName ?? ''), ['entry', 'result', 'response', 'gateway'], true)) {
            $name = $node->localName ?? '';
        }

        return trim($name);
    }

    private static function xpath(string $xml): DOMXPath
    {
        return new DOMXPath(self::document($xml));
    }

    private static function document(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement === null) {
            throw new PanosException('PAN-OS API returned XML that could not be read');
        }

        return $document;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(mixed $nodes): array
    {
        if (! $nodes instanceof \DOMNodeList) {
            return [];
        }
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function text(DOMElement $node, string $name): string
    {
        return trim((string) (self::firstText($node, [$name]) ?? ''));
    }

    /**
     * @param  list<string>  $names
     */
    private static function firstText(DOMElement $node, array $names): ?string
    {
        $wanted = array_map('strtolower', $names);
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->localName ?? ''), $wanted, true)) {
                return trim($child->textContent);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     */
    private static function firstInt(DOMElement $node, array $names): ?int
    {
        $text = self::firstText($node, $names);
        if ($text === null || $text === '' || ! preg_match('/^-?\d+$/', $text)) {
            return null;
        }

        return (int) $text;
    }

    /**
     * @param  list<string>  $names
     */
    private static function searchInt(DOMDocument $document, array $names): ?int
    {
        $wanted = array_map('strtolower', $names);
        $xpath = new DOMXPath($document);
        foreach (self::elements($xpath->query('//*')) as $node) {
            if (! in_array(strtolower($node->localName ?? ''), $wanted, true)) {
                continue;
            }
            $text = trim($node->textContent);
            if (preg_match('/^-?\d+$/', $text)) {
                return (int) $text;
            }
        }

        return null;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
