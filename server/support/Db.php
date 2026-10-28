<?php

declare(strict_types=1);

namespace support;

use app\service\Store;

/** Compatibility facade for the existing CLI fixtures; application code uses Store directly. */
final class Db
{
    public static function table(string $table): mixed { return Store::connection()->table($table); }
    public static function transaction(\Closure $callback): mixed { return Store::transaction($callback); }
    public static function statement(string $sql, array $bindings = []): int { return Store::connection()->execute($sql, $bindings); }
    public static function update(string $sql, array $bindings = []): int { return Store::connection()->execute($sql, $bindings); }
    public static function selectOne(string $sql, array $bindings = []): ?object
    {
        $row = Store::connection()->first($sql, $bindings);
        return $row === null ? null : (object) $row;
    }
}
