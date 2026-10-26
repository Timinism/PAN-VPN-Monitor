<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\View;

use LibreNmsPlugin\PanosVpn\Polling\LaravelConnection;
use LibreNmsPlugin\PanosVpn\Polling\Repository;
use Throwable;

final class Pages
{
    /**
     * @param  array<string, mixed>  $query
     */
    public static function dashboard(array $query = []): string
    {
        $vm = Presenter::dashboard(self::data(), $query, url('plugin/panos-vpn'), self::timezone(), false, time());
        $vm['device_base'] = url('device');

        return Html::dashboard($vm);
    }

    public static function device(int $deviceId): string
    {
        $vm = Presenter::dashboard(self::data(), ['fw' => $deviceId], url('plugin/panos-vpn'), self::timezone(), false, time());
        $vm['page_url'] = url('plugin/panos-vpn');

        return Html::device($vm);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $devices
     */
    public static function settings(array $settings, array $devices, ?string $notice): string
    {
        return Html::settings([
            'action' => url('plugin/settings/panos-vpn'),
            'test_action' => url('plugin/settings/panos-vpn'),
            'csrf' => function_exists('csrf_token') ? csrf_token() : '',
            'notice' => $notice,
            'settings' => $settings,
            'devices' => $devices,
        ]);
    }

    /**
     * @return array{down: int, tunnels: int, users: int}
     */
    public static function summary(): array
    {
        try {
            return (new Repository(new LaravelConnection()))->summary();
        } catch (Throwable) {
            return ['down' => 0, 'tunnels' => 0, 'users' => 0];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        try {
            return (new Repository(new LaravelConnection()))->dashboard();
        } catch (Throwable) {
            return [
                'firewalls' => [],
                'tunnels' => [],
                'tunnel_samples' => [],
                'gateways' => [],
                'gateway_samples' => [],
                'users' => [],
            ];
        }
    }

    private static function timezone(): string
    {
        $timezone = date_default_timezone_get();

        return $timezone !== '' ? $timezone : 'UTC';
    }
}
