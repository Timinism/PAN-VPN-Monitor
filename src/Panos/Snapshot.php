<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Panos;

final class Snapshot
{
    /**
     * @param  list<array<string, mixed>>  $tunnels
     * @param  list<array<string, mixed>>  $gateways
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $tunnels,
        public array $gateways,
        public array $warnings,
    ) {
    }
}
