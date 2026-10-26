<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

final class Rate
{
    /**
     * Bits per second from two byte counters.
     * A counter that went backwards is treated as a reset and skipped.
     */
    public static function bps(int|string|null $previous, int|string|null $current, int $elapsed): ?int
    {
        if ($previous === null || $current === null || $previous === '' || $current === '' || $elapsed <= 0) {
            return null;
        }

        $before = (int) $previous;
        $after = (int) $current;
        if ($after < $before) {
            return null;
        }

        return (int) round(($after - $before) * 8 / $elapsed);
    }
}
