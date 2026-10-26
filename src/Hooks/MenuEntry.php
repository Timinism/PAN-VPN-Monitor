<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNmsPlugin\PanosVpn\View\Pages;

class MenuEntry implements MenuEntryHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    /**
     * @return array{0: string, 1: array{down: int, tunnels: int, users: int}}
     */
    public function handle(string $pluginName): array
    {
        return [$pluginName.'::menu', Pages::summary()];
    }
}
