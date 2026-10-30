<?php
namespace app\service;

use InvalidArgumentException;

final class WorkspaceFolders
{
    public static function root(): string
    {
        $root = realpath((require dirname(__DIR__, 2).'/config/crabase.php')['workspace_root']);
        if (!$root || !is_dir($root) || !is_readable($root)) throw new InvalidArgumentException('Workspace folder is unavailable.');
        return $root;
    }

    public static function resolve(mixed $value, bool $allowRoot = false): string
    {
        $root = self::root();
        $value = Input::text($value, 4096);
        if (str_contains($value, "\0")) throw new InvalidArgumentException('Invalid folder path.');
        $path = realpath($value);
        if (!$path || !is_dir($path) || !is_readable($path) || ($path !== $root && !str_starts_with($path, $root.'/'))) {
            throw new InvalidArgumentException('Choose a readable folder inside the configured workspace.');
        }
        if ($path === $root && !$allowRoot) throw new InvalidArgumentException('Choose a folder inside the workspace.');
        $relative = substr($path, strlen($root) + 1);
        if (preg_match('~(^|/)\.~', $relative)) throw new InvalidArgumentException('Hidden folders cannot be projects.');
        return $path;
    }

    public static function listing(array $data): array
    {
        $root = self::root();
        $path = self::resolve($data['path'] ?? $root, true);
        $entries = scandir($path);
        if ($entries === false) throw new InvalidArgumentException('Unable to read this folder.');
        $folders = [];
        foreach ($entries as $name) {
            if (str_starts_with($name, '.')) continue;
            try { $child = self::resolve($path.'/'.$name); }
            catch (InvalidArgumentException) { continue; }
            $folders[] = ['name'=>$name, 'path'=>$child];
        }
        usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return ['root'=>$root, 'path'=>$path, 'parent'=>$path === $root ? null : dirname($path), 'folders'=>$folders];
    }
}
