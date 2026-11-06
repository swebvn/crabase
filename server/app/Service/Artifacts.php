<?php
namespace App\Service;

final class Artifacts
{
    public static function root(): string
    {
        $root = realpath((require dirname(__DIR__, 2).'/config/crabase.php')['workspace_root']);
        if (!$root || !is_dir($root)) throw new \RuntimeException('Workspace folder is unavailable.');
        return $root.'/.artifacts';
    }

    public static function directory(string $chatId): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $chatId)) throw new \InvalidArgumentException('Invalid chat ID.');
        $root = self::root();
        $path = $root.'/'.$chatId;
        if (is_link($root) || is_link($path)) throw new \RuntimeException('Artifact folders cannot be symlinks.');
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Could not create output directory.');
        return $path;
    }

    public static function publish(string $chatId, mixed $source): array
    {
        if (!is_string($source) || !str_starts_with($source, '/') || strlen($source) > 4096 || str_contains($source, "\0")) throw new \InvalidArgumentException('Expected an absolute file path.');
        $file = realpath($source);
        if (!$file || !is_file($file) || !is_readable($file)) throw new \InvalidArgumentException('File is missing or unreadable.');
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file));
        $name = substr(ltrim($name, '.'), -180) ?: 'file';
        $directory = self::directory($chatId);
        if (dirname($file) === $directory) {
            if (basename($file) !== $name) throw new \InvalidArgumentException('Artifact filename contains unsupported characters.');
            return ['name'=>$name, 'url'=>'/files/'.$chatId.'/'.$name];
        }
        // Exclusive creation preserves previously shared files, including simultaneous publications.
        do { $published = bin2hex(random_bytes(6)).'-'.$name; $target = $directory.'/'.$published; }
        while (file_exists($target));
        $input = fopen($file, 'rb');
        if (!$input) throw new \RuntimeException('Could not read file.');
        $output = fopen($target, 'xb');
        if (!$output) { fclose($input); throw new \RuntimeException('Could not publish file.'); }
        try {
            $size = fstat($input)['size'];
            if (stream_copy_to_stream($input, $output) !== $size || !fflush($output)) throw new \RuntimeException('Could not finish copying file.');
        } catch (\Throwable $e) { unlink($target); throw $e; }
        finally { fclose($input); fclose($output); }
        return ['name'=>$published, 'url'=>'/files/'.$chatId.'/'.$published];
    }

    public static function listing(string $chatId): array
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $chatId)) return [];
        $files = [];
        foreach (glob(self::root().'/'.$chatId.'/*') ?: [] as $path) {
            $file = self::resolve($chatId, basename($path));
            if (!$file) continue;
            $files[] = ['name'=>$file['name'], 'url'=>'/files/'.$chatId.'/'.$file['name'],
                'mime'=>$file['mime'], 'size'=>filesize($file['path'])];
        }
        usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $files;
    }

    public static function resolve(string $chatId, string $name): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $chatId) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $name)) return null;
        $root = self::root();
        $directory = $root.'/'.$chatId;
        if (is_link($root) || is_link($directory)) return null;
        $file = realpath($directory.'/'.$name);
        if (!$file || dirname($file) !== $directory || !is_file($file) || !is_readable($file)) return null;
        return ['path'=>$file, 'name'=>$name, 'mime'=>(new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'];
    }
}
