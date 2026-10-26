<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\View;

final class Html
{
    /**
     * @param  array<string, mixed>  $vm
     */
    public static function dashboard(array $vm): string
    {
        return self::render('dashboard.php', $vm);
    }

    /**
     * @param  array<string, mixed>  $vm
     */
    public static function device(array $vm): string
    {
        return self::render('device.php', $vm);
    }

    /**
     * @param  array<string, mixed>  $vm
     */
    public static function settings(array $vm): string
    {
        return self::render('settings.php', $vm);
    }

    /**
     * @param  array<string, mixed>  $vm
     */
    private static function render(string $template, array $vm): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/templates/app.css');
        ob_start();
        include dirname(__DIR__, 2).'/resources/templates/'.$template;

        return (string) ob_get_clean();
    }
}
