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
        if (self::hasSymlink($value)) {
            throw new InvalidArgumentException('Symlinked paths cannot be projects. Use the real folder path.');
        }
        if (self::internal($path, $defaultRoot)) {
            throw new InvalidArgumentException("Crabase's own .chats, .worktrees and .artifacts folders cannot be projects.");
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
            if ($name === '.' || $name === '..') continue;
            try { $child = self::resolve($path.'/'.$name); }
            catch (InvalidArgumentException) { continue; }
            $folders[] = ['name'=>$name, 'path'=>$child];
        }
        // Hidden folders are listed too, after the visible ones.
        usort($folders, fn($a, $b) => str_starts_with($a['name'], '.') <=> str_starts_with($b['name'], '.') ?: strnatcasecmp($a['name'], $b['name']));
        return ['root'=>$root, 'path'=>$path, 'parent'=>$path === DIRECTORY_SEPARATOR ? null : dirname($path), 'folders'=>$folders];
    }

    public static function create(array $data): array
    {
        $parent = self::resolve($data['path'] ?? self::root(), true);
        $name = Input::text($data['name'] ?? null, 255);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0") || strpbrk($name, "/\\") !== false || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('Enter a folder name without path separators.');
        }
        $path = $parent.'/'.$name;
        if (file_exists($path) || is_link($path)) throw new InvalidArgumentException('That folder already exists.');
        if (!mkdir($path, 0700)) throw new InvalidArgumentException('Could not create the folder.');
        return ['name'=>$name, 'path'=>self::resolve($path)];
    }

    /** Crabase keeps chat scratch space, managed worktrees and uploads here; they are never projects. */
    private static function internal(string $path, string $root): bool
    {
        foreach (['.chats', '.worktrees', '.artifacts'] as $folder) {
            if ($path === $root.'/'.$folder || str_starts_with($path, $root.'/'.$folder.'/')) return true;
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
