<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

use LibreNmsPlugin\PanosVpn\Panos\Client;
use LibreNmsPlugin\PanosVpn\Panos\Poller;
use Throwable;

final class Engine
{
    public function __construct(private readonly Poller $poller = new Poller())
    {
    }

    public function poll(
        Client $client,
        Repository $repository,
        int $deviceId,
        string $hostname,
        string $displayName,
        int $now,
        bool $perTunnelCounters = true,
        int $retentionDays = 14,
        ?int $sampleCap = null,
    ): void {
        $started = hrtime(true);
        try {
            $snapshot = $this->poller->collect($client, $perTunnelCounters);
            $pollMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $repository->save($deviceId, $hostname, $displayName, $snapshot, $now, $pollMs, $retentionDays, $sampleCap);
        } catch (Throwable $exception) {
            $repository->fail($deviceId, $hostname, $displayName, $exception->getMessage(), $now);
        }
    }
}
