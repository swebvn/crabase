<?php

namespace app\service;

final class ModelCatalog
{
    public const TTL = 1800;

    private static array $models = [];
    private static float $expiresAt = 0;
    private static bool $refreshing = false;

    public static function all(): array { return self::$models; }
    public static function replace(array $models): void
    {
        self::$models = array_values($models);
        self::$expiresAt = microtime(true) + self::TTL;
    }
    public static function expired(): bool { return self::$expiresAt <= microtime(true); }
    public static function beginRefresh(): bool
    {
        if (self::$refreshing) return false;
        self::$refreshing = true;
        return true;
    }
    public static function finishRefresh(): void { self::$refreshing = false; }
}
