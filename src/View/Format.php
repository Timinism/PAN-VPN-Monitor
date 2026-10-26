<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\View;

final class Format
{
    public static function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function rate(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }
        $value = (float) $bps;
        $units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
        $index = 0;
        while ($value >= 1000 && $index < 3) {
            $value /= 1000;
            $index++;
        }
        $digits = ($index === 0 || $value >= 100) ? 0 : ($value >= 10 ? 1 : 2);

        return number_format($value, $digits).' '.$units[$index];
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $value = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($value >= 1000 && $index < 4) {
            $value /= 1000;
            $index++;
        }
        $digits = $index === 0 ? 0 : 1;

        return number_format($value, $digits).' '.$units[$index];
    }

    public static function ago(?int $timestamp, int $now): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return 'not polled';
        }
        $delta = max(0, $now - $timestamp);
        if ($delta < 5) {
            return 'just now';
        }
        if ($delta < 60) {
            return $delta.'s ago';
        }
        if ($delta < 3600) {
            return intdiv($delta, 60).'m ago';
        }
        if ($delta < 86400) {
            return intdiv($delta, 3600).'h ago';
        }

        return intdiv($delta, 86400).'d ago';
    }

    public static function when(?int $timestamp, string $timezone): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }
        $zone = new \DateTimeZone($timezone);
        $moment = (new \DateTimeImmutable('@'.$timestamp))->setTimezone($zone);

        return $moment->format('M j, H:i T');
    }

    public static function crypto(?string $protocol, ?string $encryption, ?string $hash): string
    {
        $parts = array_filter([
            self::label($protocol, [
                'ESP' => 'ESP',
                'AH' => 'AH',
            ]),
            self::label($encryption, [
                'A128' => 'AES-128',
                'A192' => 'AES-192',
                'A256' => 'AES-256',
                'G128' => 'AES-GCM-128',
                'G256' => 'AES-GCM-256',
                '3DES' => '3DES',
                'DES' => 'DES',
            ]),
            self::label($hash, [
                'SHA1' => 'SHA-1',
                'SHA256' => 'SHA-256',
                'SHA384' => 'SHA-384',
                'SHA512' => 'SHA-512',
                'MD5' => 'MD5',
            ]),
        ]);

        return $parts === [] ? '—' : implode(' / ', $parts);
    }

    public static function stateLabel(string $state): string
    {
        return match (strtolower($state)) {
            'active' => 'Up',
            'inactive' => 'Down',
            'init' => 'Init',
            default => $state !== '' ? ucfirst($state) : 'Unknown',
        };
    }

    public static function stateTone(string $state): string
    {
        return match (strtolower($state)) {
            'active' => 'up',
            'inactive' => 'down',
            'init' => 'init',
            default => 'down',
        };
    }

    /**
     * @param  list<int|null>  $primary
     * @param  list<int|null>  $secondary
     */
    public static function sparkline(array $primary, array $secondary, int $width = 108, int $height = 32): string
    {
        $values = [];
        foreach ([...$primary, ...$secondary] as $value) {
            if ($value !== null) {
                $values[] = $value;
            }
        }
        if (count($primary) < 2) {
            return '';
        }
        $max = max(1, ...($values === [] ? [1] : $values));
        $primaryPath = self::sparkPath($primary, $width, $height, $max);
        $secondaryPath = self::sparkPath($secondary, $width, $height, $max);

        return '<svg class="pv-spark" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" role="img" aria-label="Bandwidth history">'
            .'<path d="'.self::h($secondaryPath).'" class="pv-spark-out" />'
            .'<path d="'.self::h($primaryPath).'" class="pv-spark-in" />'
            .'</svg>';
    }

    /**
     * @param  list<int|null>  $series
     */
    private static function sparkPath(array $series, int $width, int $height, int $max): string
    {
        $count = count($series);
        if ($count < 2) {
            return '';
        }
        $step = $width / ($count - 1);
        $commands = [];
        $drawing = false;
        foreach ($series as $index => $value) {
            if ($value === null) {
                $drawing = false;
                continue;
            }
            $x = round($index * $step, 2);
            $y = round($height - ($value / $max) * ($height - 4) - 2, 2);
            $commands[] = ($drawing ? 'L ' : 'M ').$x.','.$y;
            $drawing = true;
        }

        return implode(' ', $commands);
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function label(?string $value, array $map): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $key = strtoupper(trim($value));

        return $map[$key] ?? trim($value);
    }
}
