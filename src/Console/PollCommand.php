<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Console;

use Illuminate\Console\Command;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNmsPlugin\PanosVpn\Polling\LibreNmsRunner;

class PollCommand extends Command
{
    protected $signature = 'panos-vpn:poll {device? : Device id or hostname}';

    protected $description = 'Poll Palo Alto site-to-site tunnels and GlobalProtect users';

    public function handle(PluginManagerInterface $plugins): int
    {
        if (! $plugins->pluginEnabled('panos-vpn')) {
            $this->error('The Palo Alto VPN plugin is disabled.');

            return 1;
        }

        $lines = (new LibreNmsRunner())->pollSpec($this->argument('device'));
        if ($lines === []) {
            $this->warn('No PAN-OS devices matched.');

            return 0;
        }
        foreach ($lines as $line) {
            $this->line($line);
        }

        return 0;
    }
}
