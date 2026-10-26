<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Listeners;

use LibreNmsPlugin\PanosVpn\Polling\LibreNmsRunner;
use Throwable;

class PollAfterDevice
{
    public function handle(object $event): void
    {
        $device = $event->device ?? null;
        if (! is_object($device) || ($device->os ?? '') !== 'panos') {
            return;
        }

        try {
            (new LibreNmsRunner())->pollDevice($device);
        } catch (Throwable $exception) {
            if (function_exists('logger')) {
                logger()->error('panos-vpn poll failed: '.$exception->getMessage());
            }
        }
    }
}
