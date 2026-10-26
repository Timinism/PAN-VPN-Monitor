<?php

declare(strict_types=1);

use LibreNmsPlugin\PanosVpn\Panos\Client;
use LibreNmsPlugin\PanosVpn\Panos\PanosException;
use LibreNmsPlugin\PanosVpn\Panos\Poller;
use LibreNmsPlugin\PanosVpn\Polling\Engine;
use LibreNmsPlugin\PanosVpn\Polling\PdoConnection;
use LibreNmsPlugin\PanosVpn\Polling\Repository;
use LibreNmsPlugin\PanosVpn\Polling\Schema;
use LibreNmsPlugin\PanosVpn\Support\SettingsMerger;
use LibreNmsPlugin\PanosVpn\View\Html;
use LibreNmsPlugin\PanosVpn\View\Presenter;

final class Lab
{
    private const SAMPLE_CAP = 40;

    private function __construct(
        private readonly Repository $repository,
        private readonly string $settingsPath,
        private array $settings,
        private readonly int $loginBase,
    ) {
    }

    public static function open(string $directory): self
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the lab data directory');
        }
        $pdo = new PDO('sqlite:'.$directory.'/lab.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::install($pdo);
        $settingsPath = $directory.'/settings.json';
        $settings = is_file($settingsPath)
            ? json_decode((string) file_get_contents($settingsPath), true, 512, JSON_THROW_ON_ERROR)
            : self::defaultSettings();
        $lab = new self(new Repository(new PdoConnection($pdo)), $settingsPath, $settings, time() - 86400 * 3);
        if ($lab->repository->latestPoll() === null) {
            $now = time();
            for ($ago = 24; $ago >= 0; $ago--) {
                $lab->poll($now - $ago * 5);
            }
        }

        return $lab;
    }

    public function tick(): void
    {
        $latest = $this->repository->latestPoll();
        if ($latest !== null && time() - $latest < 4) {
            return;
        }
        $this->poll(time());
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public function saveSettings(array $incoming): void
    {
        $this->settings = SettingsMerger::merge($this->settings, $incoming);
        $this->writeSettings();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(int $deviceId, string $apiKey): array
    {
        $device = self::firewalls()[$deviceId] ?? null;
        if ($device === null) {
            return ['ok' => false, 'message' => 'That firewall is not in the lab.'];
        }
        $saved = $this->settings['devices'][(string) $deviceId]['api_key'] ?? '';
        $key = $apiKey !== '' ? $apiKey : (string) $saved;
        try {
            $snapshot = (new Poller())->collect(new PanosMock($device, $key, time(), $this->loginBase), true);
        } catch (PanosException $exception) {
            return ['ok' => false, 'message' => $device['display_name'].' — '.$exception->getMessage()];
        }
        $users = 0;
        foreach ($snapshot->gateways as $gateway) {
            $users += (int) $gateway['current_users'];
        }

        return [
            'ok' => true,
            'message' => $device['display_name'].' answered show vpn flow with '.count($snapshot->tunnels).' site-to-site tunnels and '.$users.' GlobalProtect users.',
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function dashboard(array $query): string
    {
        $vm = Presenter::dashboard($this->repository->dashboard(), $query, '/', 'America/Chicago', true, time());
        $vm['device_base'] = '/device';

        return Html::dashboard($vm);
    }

    public function device(int $deviceId): ?string
    {
        if (! isset(self::firewalls()[$deviceId])) {
            return null;
        }
        $vm = Presenter::dashboard($this->repository->dashboard(), ['fw' => $deviceId], '/', 'America/Chicago', true, time());
        $vm['page_url'] = '/';

        return Html::device($vm);
    }

    public function settings(?string $notice): string
    {
        $devices = [];
        foreach (self::firewalls() as $id => $firewall) {
            $devices[] = [
                'device_id' => $id,
                'hostname' => $firewall['hostname'],
                'display_name' => $firewall['display_name'],
            ];
        }

        return Html::settings([
            'action' => '/settings',
            'test_action' => '/settings/test',
            'csrf' => $_SESSION['csrf'] ?? '',
            'notice' => $notice,
            'settings' => $this->settings,
            'devices' => $devices,
        ]);
    }

    public function firewallName(int $deviceId): string
    {
        return self::firewalls()[$deviceId]['display_name'] ?? 'Firewall';
    }

    private function poll(int $now): void
    {
        $engine = new Engine();
        foreach (self::firewalls() as $id => $firewall) {
            $config = $this->settings['devices'][(string) $id] ?? [];
            if (empty($config['enabled'])) {
                continue;
            }
            $engine->poll(
                new PanosMock($firewall, (string) ($config['api_key'] ?? ''), $now, $this->loginBase),
                $this->repository,
                $id,
                $firewall['hostname'],
                $firewall['display_name'],
                $now,
                (bool) ($this->settings['per_tunnel_counters'] ?? true),
                (int) ($this->settings['retention_days'] ?? 14),
                self::SAMPLE_CAP,
            );
        }
    }

    private function writeSettings(): void
    {
        file_put_contents($this->settingsPath, json_encode($this->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultSettings(): array
    {
        $devices = [];
        foreach (self::firewalls() as $id => $firewall) {
            $devices[(string) $id] = [
                'enabled' => true,
                'api_key' => $firewall['default_key'],
                'host' => '',
                'port' => 443,
                'target' => '',
            ];
        }

        return SettingsMerger::merge([], [
            'verify_tls' => '0',
            'timeout' => 20,
            'retention_days' => 14,
            'per_tunnel_counters' => '1',
            'devices' => $devices,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function firewalls(): array
    {
        return [
            1 => [
                'hostname' => 'hq-fw1.corp.example',
                'display_name' => 'HQ-FW1',
                'default_key' => 'lab-hq',
                'tunnels' => [
                    self::tunnel('HQ-to-DC', 10, 'active', '198.51.100.10', '203.0.113.10', 'tunnel.10', 'ethernet1/1', 'off', null, 'IKE-DC', 42_000_000, 18_000_000),
                    self::tunnel('HQ-to-Austin', 11, 'active', '198.51.100.21', '203.0.113.10', 'tunnel.11', 'ethernet1/1', 'off', null, 'IKE-AUS', 6_400_000, 2_100_000),
                    self::tunnel('HQ-to-London', 12, 'active', '198.51.100.44', '203.0.113.10', 'tunnel.12', 'ethernet1/2', 'on', 'up', 'IKE-LON', 12_500_000, 9_800_000),
                    self::tunnel('HQ-to-Plant', 13, 'inactive', '198.51.100.80', '203.0.113.10', 'tunnel.13', 'ethernet1/1', 'on', 'down', 'IKE-PLANT', 0, 0, true),
                    self::tunnel('HQ-to-Vendor', 14, 'init', '203.0.113.9', '203.0.113.10', 'tunnel.14', 'ethernet1/2', 'off', null, 'IKE-VENDOR', 0, 0, false, true),
                ],
                'gateways' => [
                    ['name' => 'GP-External', 'max' => 250, 'base' => 36, 'swing' => 3, 'prefix' => 'noc', 'octet' => 10],
                    ['name' => 'GP-Contractors', 'max' => 50, 'base' => 7, 'swing' => 2, 'prefix' => 'vendor', 'octet' => 20],
                ],
            ],
            2 => [
                'hostname' => 'dc-fw1.corp.example',
                'display_name' => 'DC-FW1',
                'default_key' => 'lab-dc',
                'tunnels' => [
                    self::tunnel('DC-to-HQ', 1, 'active', '203.0.113.10', '198.51.100.4', 'tunnel.1', 'ethernet1/1', 'off', null, 'IKE-HQ', 38_000_000, 16_000_000),
                    self::tunnel('DC-to-DR', 2, 'active', '203.0.113.20', '198.51.100.4', 'tunnel.2', 'ethernet1/1', 'on', 'up', 'IKE-DR', 22_000_000, 22_000_000),
                    self::tunnel('DC-to-AWS', 3, 'active', '203.0.113.30', '198.51.100.4', 'tunnel.3', 'ethernet1/2', 'off', null, 'IKE-AWS', 54_000_000, 47_000_000),
                ],
                'gateways' => [
                    ['name' => 'GP-Jump', 'max' => 25, 'base' => 2, 'swing' => 1, 'prefix' => 'jump', 'octet' => 30],
                ],
            ],
            3 => [
                'hostname' => 'branch-aus.corp.example',
                'display_name' => 'AUS-Branch',
                'default_key' => 'lab-aus',
                'tunnels' => [
                    self::tunnel('AUS-to-HQ', 1, 'active', '203.0.113.10', '198.51.100.21', 'tunnel.1', 'ethernet1/1', 'on', 'up', 'IKE-HQ', 6_200_000, 2_000_000),
                    self::tunnel('AUS-to-DC', 2, 'inactive', '198.51.100.4', '198.51.100.21', 'tunnel.2', 'ethernet1/1', 'on', 'down', 'IKE-DC', 0, 0, true),
                ],
                'gateways' => [],
            ],
            4 => [
                'hostname' => 'edge-lab.corp.example',
                'display_name' => 'Edge-Lab',
                'default_key' => '',
                'tunnels' => [
                    self::tunnel('Lab-to-HQ', 1, 'active', '203.0.113.10', '198.51.100.50', 'tunnel.1', 'ethernet1/1', 'off', null, 'IKE-HQ', 1_200_000, 800_000),
                ],
                'gateways' => [
                    ['name' => 'GP-Lab', 'max' => 10, 'base' => 1, 'swing' => 1, 'prefix' => 'lab', 'octet' => 40],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tunnel(
        string $name,
        int $id,
        string $state,
        string $peer,
        string $local,
        string $inner,
        string $outer,
        string $monitor,
        ?string $monitorStatus,
        string $gateway,
        int $out,
        int $in,
        bool $frozen = false,
        bool $noCounters = false,
    ): array {
        return compact('name', 'id', 'state', 'peer', 'local', 'inner', 'outer', 'monitor', 'monitorStatus', 'gateway', 'out', 'in', 'frozen', 'noCounters');
    }
}

final class PanosMock implements Client
{
    /**
     * @param  array<string, mixed>  $firewall
     */
    public function __construct(
        private readonly array $firewall,
        private readonly string $apiKey,
        private readonly int $now,
        private readonly int $loginBase,
    ) {
    }

    public function op(string $command): string
    {
        if ($this->apiKey === '') {
            return '<response status="error"><result><msg>Invalid credential</msg></result></response>';
        }
        if (str_contains($command, '<flow></flow>')) {
            return $this->flow();
        }
        if (str_contains($command, 'ipsec-sa')) {
            return $this->ipsecSa();
        }
        if (str_contains($command, 'statistics')) {
            return $this->statistics();
        }
        if (str_contains($command, '<gateway></gateway>')) {
            return $this->gatewayInventory();
        }
        if (str_contains($command, 'current-user')) {
            return $this->users();
        }

        return '<response status="success"><result></result></response>';
    }

    private function flow(): string
    {
        $entries = '';
        foreach ($this->firewall['tunnels'] as $tunnel) {
            $bytes = '';
            if (! $tunnel['noCounters']) {
                $bytes = '<encap-bytes>'.$this->bytes($tunnel, 'out').'</encap-bytes><decap-bytes>'.$this->bytes($tunnel, 'in').'</decap-bytes>';
            }
            $status = $tunnel['monitorStatus'] ? '<mon-status>'.$this->esc((string) $tunnel['monitorStatus']).'</mon-status>' : '';
            $entries .= '<entry>'
                .'<name>'.$this->esc($tunnel['name']).'</name>'
                .'<id>'.$tunnel['id'].'</id>'
                .'<state>'.$this->esc($tunnel['state']).'</state>'
                .'<peerip>'.$this->esc($tunnel['peer']).'</peerip>'
                .'<localip>'.$this->esc($tunnel['local']).'</localip>'
                .'<inner-if>'.$this->esc($tunnel['inner']).'</inner-if>'
                .'<outer-if>'.$this->esc($tunnel['outer']).'</outer-if>'
                .'<gateway>'.$this->esc($tunnel['gateway']).'</gateway>'
                .'<mon>'.$this->esc($tunnel['monitor']).'</mon>'
                .$status
                .$bytes
                .'</entry>';
        }
        $count = count($this->firewall['tunnels']);

        return '<response status="success"><result><total>'.$count.'</total><num_ipsec>'.$count.'</num_ipsec><IPSec>'.$entries.'</IPSec></result></response>';
    }

    private function ipsecSa(): string
    {
        $entries = '';
        foreach ($this->firewall['tunnels'] as $tunnel) {
            if ($tunnel['state'] !== 'active') {
                continue;
            }
            $entries .= '<entry><name>'.$this->esc($tunnel['name']).'</name><gateway>'.$this->esc($tunnel['gateway']).'</gateway><proto>ESP</proto><enc>G256</enc><hash>SHA256</hash><tid>'.$tunnel['id'].'</tid></entry>';
        }

        return '<response status="success"><result><ntun>'.substr_count($entries, '<entry>').'</ntun><entries>'.$entries.'</entries></result></response>';
    }

    private function statistics(): string
    {
        $entries = '';
        foreach ($this->firewall['gateways'] as $gateway) {
            $current = $this->userCount($gateway, $this->now);
            $previous = $this->userCount($gateway, $this->now - 600);
            $entries .= '<entry name="'.$this->esc($gateway['name']).'"><gateway>'.$this->esc($gateway['name']).'</gateway><current-users>'.$current.'</current-users><previous-users>'.$previous.'</previous-users></entry>';
        }

        return '<response status="success"><result>'.$entries.'</result></response>';
    }

    private function gatewayInventory(): string
    {
        $entries = '';
        foreach ($this->firewall['gateways'] as $gateway) {
            $entries .= '<entry><name>'.$this->esc($gateway['name']).'</name><max-users>'.$gateway['max'].'</max-users></entry>';
        }

        return '<response status="success"><result>'.$entries.'</result></response>';
    }

    private function users(): string
    {
        $blocks = '';
        foreach ($this->firewall['gateways'] as $gateway) {
            $count = $this->userCount($gateway, $this->now);
            $entries = '';
            for ($index = 1; $index <= $count; $index++) {
                $kind = $index % 5 === 0 ? 'SSL' : 'IPSec';
                $client = match ($index % 3) {
                    0 => 'GlobalProtect 6.2.8',
                    1 => 'GlobalProtect 6.3.1',
                    default => 'GlobalProtect iOS 6.1',
                };
                $computer = strtoupper($gateway['prefix']).'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $entries .= '<entry>'
                    .'<username>'.$this->esc($gateway['prefix'].'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)).'</username>'
                    .'<computer>'.$this->esc($computer).'</computer>'
                    .'<client>'.$this->esc($client).'</client>'
                    .'<virtual-ip>10.64.'.$gateway['octet'].'.'.(10 + $index).'</virtual-ip>'
                    .'<public-ip>203.0.113.'.(($gateway['octet'] + $index) % 200 + 20).'</public-ip>'
                    .'<tunnel-type>'.$kind.'</tunnel-type>'
                    .'<login-time-utc>'.($this->loginBase - $index * 2400 - $gateway['octet'] * 30).'</login-time-utc>'
                    .'</entry>';
            }
            $blocks .= '<gateway name="'.$this->esc($gateway['name']).'">'.$entries.'</gateway>';
        }

        return '<response status="success"><result>'.$blocks.'</result></response>';
    }

    /**
     * @param  array<string, mixed>  $tunnel
     */
    private function bytes(array $tunnel, string $direction): int
    {
        if ($tunnel['frozen']) {
            return 8_000_000;
        }
        $base = (float) $tunnel[$direction];
        $amplitude = 0.22 * $base;
        $integral = $base * $this->now - 40 * $amplitude * cos($this->now / 40);

        return (int) round($integral / 8) + 2_000_000_000;
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function userCount(array $gateway, int $now): int
    {
        $count = (int) round($gateway['base'] + sin($now / 40) * $gateway['swing']);

        return max(0, min($gateway['max'], $count));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }
}
