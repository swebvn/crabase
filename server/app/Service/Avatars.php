<?php
namespace App\Service;

final class Avatars
{
    public static function directory(): string
    {
        $config = require dirname(__DIR__, 2).'/config/crabase.php';
        $path = dirname($config['database_path']).'/avatars';
        if (is_link($path)) throw new \RuntimeException('Avatar folder cannot be a symlink.');
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Could not create avatar folder.');
        return $path;
    }

    public static function resolve(string $name): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|gif|webp)$/D', $name)) return null;
        $path = self::directory().'/'.$name;
        return !is_link($path) && is_file($path) && is_readable($path) ? $path : null;
    }

}
