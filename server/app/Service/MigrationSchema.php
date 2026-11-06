<?php

declare(strict_types=1);

namespace App\Service;

use Crab\Contracts\Database\Connection;

final class MigrationSchema
{
    public static function createOrValidate(Connection $database, string $table, string $sql): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid migration table name.');
        }

        $database->execute($sql);
        $expected = new \PDO('sqlite::memory:');
        $expected->exec($sql);
        $columns = $expected->query('PRAGMA table_info('.$table.')')->fetchAll(\PDO::FETCH_ASSOC);
        $actual = $database->select('PRAGMA table_info('.$table.')');
        if ($actual !== $columns) {
            throw new \RuntimeException("Existing {$table} schema differs from the baseline.");
        }
        if ($database->select('PRAGMA foreign_key_check('.$table.')') !== []) {
            throw new \RuntimeException("{$table} has invalid foreign keys.");
        }
    }
}

