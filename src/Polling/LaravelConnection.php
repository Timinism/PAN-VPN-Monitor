<?php

declare(strict_types=1);

namespace LibreNmsPlugin\PanosVpn\Polling;

use Illuminate\Support\Facades\DB;

final class LaravelConnection implements Connection
{
    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);

        return $rows[0] ?? null;
    }

    public function all(string $sql, array $params = []): array
    {
        return array_map(static fn ($row): array => (array) $row, DB::select($sql, $params));
    }

    public function exec(string $sql, array $params = []): void
    {
        DB::statement($sql, $params);
    }

    public function lastInsertId(): int
    {
        return (int) DB::connection()->getPdo()->lastInsertId();
    }

    public function transaction(callable $callback): void
    {
        DB::transaction($callback);
    }
}
