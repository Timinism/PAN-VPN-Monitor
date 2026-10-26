<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNmsPlugin\PanosVpn\View\Pages;

class Page implements SinglePageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $pluginName): array
    {
        return [
            'content_view' => $pluginName.'::page',
            'title' => 'Palo Alto VPN',
            'dashboard_html' => Pages::dashboard(),
        ];
    }
}
