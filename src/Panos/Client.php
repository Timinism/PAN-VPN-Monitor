<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Panos;

interface Client
{
    /**
     * Run one PAN-OS operational command and return the raw XML body.
     * The command is an XML fragment such as <show><vpn><flow></flow></vpn></show>.
     */
    public function op(string $command): string;
}
