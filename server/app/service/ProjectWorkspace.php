<?php
namespace app\service;

use InvalidArgumentException;
final class ProjectWorkspace
{
    private const MAX_FILE_SIZE = 1000000;
    private const MAX_PREVIEW_SIZE = 5000000;

    public static function context(array $data): array
    {
        $path = self::root($data);
        [$git, $branch] = self::gitInfo($path);
        $result = ['branch'=>$branch, 'path'=>$path, 'worktree'=>null, 'detached'=>false];
        if (!$git) return $result;
        if ($branch === null) {
            [$status, $commit] = self::command($path, ['rev-parse','--short','HEAD']);
            if ($status !== 0) throw new \RuntimeException('Could not read Git HEAD.');
            $result['branch'] = trim($commit);
            $result['detached'] = true;
        }
        [$status, $directories] = self::command($path, ['rev-parse','--path-format=absolute','--git-dir','--git-common-dir','--show-toplevel']);
        $directories = explode("\n", trim($directories));
        if ($status !== 0 || count($directories) !== 3) throw new \RuntimeException('Could not read worktree context.');
        if ($directories[0] !== $directories[1]) $result['worktree'] = basename($directories[2]);
        return $result;
    }

    public static function snapshot(array $data): array
    {
        $root = self::root($data);
        [$git, $branch] = self::gitInfo($root);
        $ignored = $git ? self::gitPaths($root, true) : [];
        // ponytail: keep the 5,000-entry snapshot cap; use lazy folder loading if larger trees need it.
        $paths = array_slice(array_values(array_unique(array_merge(
            $git ? self::gitPaths($root) : self::diskPaths($root), $ignored
        ))), 0, 5000);
        natcasesort($paths);
        return [
            'paths' => array_values($paths),
            'ignored' => array_values(array_intersect($ignored, $paths)),
            'git' => $git,
            'branch' => $branch,
            'changes' => $git ? self::changesFor($root) : [],
        ];
    }

    public static function file(array $data): array
    {
        $root = self::root($data);
        $relative = self::relative($data['path'] ?? null);
        $path = self::resolvedFile($root, $relative);
        if (filesize($path) > self::MAX_PREVIEW_SIZE) {
            return ['path'=>$relative, 'contents'=>'', 'hash'=>'', 'unsupported'=>'File is too large to preview (maximum 5 MB).'];
        }
        $contents = file_get_contents($path);
        $image = $contents === false ? false : @getimagesizefromstring($contents);
        if ($image && in_array($image['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon'], true)) {
            return ['path'=>$relative, 'contents'=>'', 'hash'=>hash('sha256', $contents),
                'image'=>'data:'.$image['mime'].';base64,'.base64_encode($contents)];
        }
        if ($contents === false) throw new InvalidArgumentException('File is unreadable.');
        if (str_contains($contents, "\0") || !preg_match('//u', $contents)) {
            return ['path'=>$relative, 'contents'=>'', 'hash'=>'', 'unsupported'=>'Unsupported file'];
        }
        return ['path'=>$relative, 'contents'=>$contents, 'hash'=>hash('sha256', $contents)];
    }

    public static function save(array $data): array
    {
        $root = self::root($data);
        $relative = self::relative($data['path'] ?? null);
        $contents = $data['contents'] ?? null;
        $expected = $data['hash'] ?? null;
        if (!is_string($contents) || strlen($contents) > self::MAX_FILE_SIZE || str_contains($contents, "\0") || !preg_match('//u', $contents)) {
            throw new InvalidArgumentException('Only text files up to 1 MB can be saved.');
        }
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new InvalidArgumentException('Invalid file version.');
        }
        $path = self::resolvedFile($root, $relative);
        $current = file_get_contents($path);
        if ($current === false || !hash_equals($expected, hash('sha256', $current))) {
            throw new InvalidArgumentException('This file changed on disk. Reload it before saving.');
        }
        if (!is_writable($path)) throw new InvalidArgumentException('File is not writable.');
        $temporary = tempnam(dirname($path), '.crabase-');
        if ($temporary === false) throw new \RuntimeException('Unable to create a temporary file.');
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, fileperms($path) & 0777)
                || !rename($temporary, $path)) {
                throw new \RuntimeException('Unable to save the file.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        return ['path'=>$relative, 'hash'=>hash('sha256', $contents)];
    }

    public static function diff(array $data): array
    {
        $root = self::root($data);
        $relative = self::relative($data['path'] ?? null);
        [$git] = self::gitInfo($root);
        if (!$git) throw new InvalidArgumentException('This project is not a Git repository.');
        $change = array_values(array_filter(self::changesFor($root), fn($item) => $item['path'] === $relative))[0] ?? null;
        if (!$change) throw new InvalidArgumentException('This file has no Git changes.');
        if ($change['status'] === 'untracked') {
            self::resolvedFile($root, $relative);
            [$status, $patch] = self::command($root, ['diff','--no-index','--no-color','--','/dev/null',$relative]);
            if (!in_array($status, [0, 1], true)) throw new InvalidArgumentException('Unable to read this diff.');
        } else {
            [$head] = self::command($root, ['rev-parse','--verify','HEAD']);
            $args = $head === 0
                ? ['diff','--no-ext-diff','--no-color','HEAD','--',$relative]
                : ['diff','--no-ext-diff','--no-color','--cached','--',$relative];
            [$status, $patch] = self::command($root, $args);
            if ($status !== 0) throw new InvalidArgumentException('Unable to read this diff.');
        }
        if (strlen($patch) > self::MAX_FILE_SIZE) throw new InvalidArgumentException('Diff is too large to preview.');
        return ['path'=>$relative, 'patch'=>$patch];
    }

    private static function root(array $data): string
    {
        $id = Input::text($data['project_id'] ?? null, 64);
        $project = Store::connection()->table('projects')->where('id', $id)->first();
        if (!$project) throw new InvalidArgumentException('Project not found.');
        return self::workspacePath($project);
    }

    /** @param array<string, mixed> $project */
    public static function workspacePath(array $project): string
    {
        if (!$project['parent_id']) return WorkspaceFolders::resolve($project['path']);
        $base = WorkspaceFolders::root().'/.worktrees';
        $expected = (string)$project['path'];
        $parent = Store::connection()->table('projects')->where('id', $project['parent_id'])->first();
        $directory = $parent ? $base.'/'.Worktrees::projectFolder($parent['name']) : '';
        $legacy = $base.'/'.$project['parent_id'].'/'.$project['id'];
        if ($expected !== $legacy && (!$parent || dirname($expected) !== $directory || !preg_match('/^[a-z]+-[a-z]+$/D', basename($expected)))) {
            throw new InvalidArgumentException('Invalid worktree folder.');
        }
        foreach ([dirname(dirname($expected)), dirname($expected), $expected] as $path) {
            if (is_link($path)) throw new InvalidArgumentException('Worktree folder cannot be a symlink.');
        }
        if ($project['path'] !== $expected || realpath($expected) !== $expected || !is_dir($expected) || !is_readable($expected)) {
            throw new InvalidArgumentException('Worktree folder is unavailable.');
        }
        return $expected;
    }

    private static function relative(mixed $value): string
    {
        $path = Input::text($value, 4096);
        if (str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw new InvalidArgumentException('Invalid project path.');
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new InvalidArgumentException('Invalid project path.');
        }
        return str_replace('\\', '/', $path);
    }

    private static function resolvedFile(string $root, string $relative): string
    {
        $candidate = $root.'/'.$relative;
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) throw new InvalidArgumentException('Symlink files cannot be previewed.');
        }
        $path = realpath($candidate);
        if (!$path || !str_starts_with($path, $root.'/') || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('File is missing or unreadable.');
        }
        return $path;
    }

    private static function gitInfo(string $root): array
    {
        [$status, $inside] = self::command($root, ['rev-parse','--is-inside-work-tree']);
        if ($status !== 0 || trim($inside) !== 'true') return [false, null];
        [$branchStatus, $branch] = self::command($root, ['symbolic-ref','--quiet','--short','HEAD']);
        return [true, $branchStatus === 0 ? trim($branch) : null];
    }

    private static function gitPaths(string $root, bool $ignored = false): array
    {
        [$status, $output] = self::command($root, ['ls-files','-z',$ignored ? '--ignored' : '--cached','--others','--exclude-standard','--','.']);
        if ($status !== 0) return [];
        $paths = array_values(array_filter(explode("\0", $output), fn($path) => $path !== ''));
        natcasesort($paths);
        return array_slice(array_values($paths), 0, 5000);
    }

    private static function diskPaths(string $root): array
    {
        $paths = [];
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($item) {
            return !$item->isLink() && $item->getFilename() !== '.git';
        });
        foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = substr($item->getPathname(), strlen($root) + 1);
            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative).($item->isDir() ? '/' : '');
            if (count($paths) >= 5000) break;
        }
        natcasesort($paths);
        return array_values($paths);
    }

    private static function changesFor(string $root): array
    {
        [$status, $output] = self::command($root, ['status','--porcelain=v1','-z','--untracked-files=all','--','.']);
        if ($status !== 0) return [];
        $records = explode("\0", $output);
        $changes = [];
        for ($index = 0; $index < count($records); $index++) {
            $record = $records[$index];
            if (strlen($record) < 4) continue;
            $code = substr($record, 0, 2);
            $path = substr($record, 3);
            if (strpbrk($code, 'RC') !== false) $index++;
            $kind = $code === '??' ? 'untracked'
                : (str_contains($code, 'D') ? 'deleted'
                : (strpbrk($code, 'RC') !== false ? 'renamed'
                : (str_contains($code, 'A') ? 'added' : 'modified')));
            $changes[] = ['path'=>$path, 'status'=>$kind, 'code'=>trim($code)];
        }
        usort($changes, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        return $changes;
    }

    public static function command(string $root, array $args): array
    {
        $process = proc_open(array_merge(['git','-C',$root], $args), [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start Git.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = $error = '';
        $deadline = microtime(true) + 10;
        do {
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                throw new \RuntimeException('Git operation timed out. Check the repository before retrying.');
            }
            usleep(1000);
        } while (true);
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        return [$state['exitcode'] >= 0 ? $state['exitcode'] : $status, $output, $error];
    }
}
