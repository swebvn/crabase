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
        $value = Input::text($value, 4096);
        if (str_contains($value, "\0") || !str_starts_with($value, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Enter an absolute folder path.');
        }
        $path = realpath($value);
        if (!$path || !is_dir($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Choose a readable folder.');
        }
        $defaultRoot = self::root();
        if (self::hasSymlink($value) || ($path !== $defaultRoot && self::hasHiddenSegment($path))) {
            throw new InvalidArgumentException('Hidden folders and symlink paths cannot be projects.');
        }
        if (!$allowRoot && ($path === DIRECTORY_SEPARATOR || $path === $defaultRoot)) {
            throw new InvalidArgumentException('Choose a project folder, not a workspace root.');
        }
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
        return ['root'=>$root, 'path'=>$path, 'parent'=>$path === DIRECTORY_SEPARATOR ? null : dirname($path), 'folders'=>$folders];
    }

    public static function create(array $data): array
    {
        $parent = self::resolve($data['path'] ?? self::root(), true);
        $name = Input::text($data['name'] ?? null, 255);
        if (str_contains($name, "\0") || strpbrk($name, "/\\") !== false || preg_match('/[\x00-\x1F\x7F]/', $name) || str_starts_with($name, '.')) {
            throw new InvalidArgumentException('Enter a visible folder name without path separators.');
        }
        $path = $parent.'/'.$name;
        if (file_exists($path) || is_link($path)) throw new InvalidArgumentException('That folder already exists.');
        if (!mkdir($path, 0700)) throw new InvalidArgumentException('Could not create the folder.');
        return ['name'=>$name, 'path'=>self::resolve($path)];
    }

    private static function hasHiddenSegment(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) return true;
        }
        return false;
    }

    private static function hasSymlink(string $path): bool
    {
        $current = DIRECTORY_SEPARATOR;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') continue;
            $current .= ($current === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR).$segment;
            if (is_link($current)) return true;
        }
        return false;
    }
}
