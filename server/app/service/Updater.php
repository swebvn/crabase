<?php

declare(strict_types=1);

namespace app\service;

use Crab\ServerFiles;
use InvalidArgumentException;

/** Release checks and the hand-off to the detached updater process. */
final class Updater
{
    private const REPOSITORY = 'swebvn/crabase';
    private const STATUS = 'storage/update.json';
    private const RELEASE = 'storage/update-release.json';
    private const TAG = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/';

    /** Combines a release fetched by bin/release.php with local checkout state; never calls the network. */
    public static function check(array $release): array
    {
        $release = self::cleanRelease($release);
        $root = self::root();
        $current = self::current($root);
        $daemon = ServerFiles::in($root . '/server')->running() !== null;
        $available = $current['tag'] !== null && self::compareTags($release['tag'], $current['tag']) > 0;
        $clean = self::clean($root);
        $reason = null;
        if ($current['tag'] === null) $reason = 'This checkout is not on a release tag.';
        elseif (!$available) $reason = 'This installation is up to date.';
        elseif (!$daemon) $reason = 'One-click updates require Cua daemon mode (php server/cua start -d).';
        elseif (!$clean) $reason = 'The deployment checkout has local changes. Update it over SSH first.';
        self::writeJson(self::RELEASE, ['checked_at'=>time(), 'release'=>$release]);
        return ['current'=>$current, 'latest'=>$release, 'available'=>$available, 'can_update'=>$available && $daemon && $clean, 'reason'=>$reason, 'status'=>self::status()];
    }

    /** Fetches the latest stable release; blocking, so only bin/release.php may call it. */
    public static function latestRelease(): array
    {
        $context = stream_context_create(['http'=>['method'=>'GET','timeout'=>8,'ignore_errors'=>true,'header'=>"Accept: application/vnd.github+json\r\nUser-Agent: Crabase-Updater\r\n"]]);
        $raw = @file_get_contents('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest', false, $context);
        $value = $raw === false ? null : json_decode($raw, true);
        if (!is_array($value) || !empty($value['draft']) || !empty($value['prerelease'])) throw new InvalidArgumentException('Could not read a stable Crabase release from GitHub.');
        return self::cleanRelease(['tag'=>$value['tag_name'] ?? '', 'name'=>$value['name'] ?? '', 'url'=>$value['html_url'] ?? '', 'body'=>$value['body'] ?? '', 'published_at'=>$value['published_at'] ?? '']);
    }

    public static function status(): array
    {
        $value = self::readJson(self::STATUS);
        return is_array($value) ? $value : ['state'=>'idle'];
    }

    /** @param array{active_jobs?: int, active_terminals?: int} $activity */
    public static function start(array $activity = []): array
    {
        $lockPath = self::root() . '/server/storage/update.lock';
        if (!is_dir(dirname($lockPath))) @mkdir(dirname($lockPath), 0770, true);
        $lock = @fopen($lockPath, 'c');
        if (!$lock) throw new InvalidArgumentException('Could not open the update lock.');
        // Never block the WebSocket worker: bin/update.php holds this lock for the whole update.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $status = self::status();
            if (in_array($status['state'] ?? '', ['queued','running'], true)) return $status;
            throw new InvalidArgumentException('Another update is already running.');
        }
        try {
        $status = self::status();
        // With the lock free, "running" means a crashed updater; "queued" is only valid while the child starts.
        if (($status['state'] ?? '') === 'queued' && strtotime((string)($status['updated_at'] ?? '')) > time() - 60) return $status;
        if ((int)($activity['active_jobs'] ?? 0) > 0 || (int)($activity['active_terminals'] ?? 0) > 0) {
            throw new InvalidArgumentException('Finish queued jobs and close terminals before updating.');
        }
        $cached = self::readJson(self::RELEASE);
        if (!is_array($cached['release'] ?? null) || (int)($cached['checked_at'] ?? 0) < time() - 900) throw new InvalidArgumentException('Check for updates again before installing.');
        $info = self::check($cached['release']);
        if (!$info['can_update']) throw new InvalidArgumentException((string)($info['reason'] ?? 'This release cannot be installed.'));
        $tag = (string)$info['latest']['tag'];
        self::writeStatus($status = ['state'=>'queued','tag'=>$tag,'started_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'message'=>'Update queued.']);
        // Worker sockets lack O_CLOEXEC; close every inherited descriptor so the stopped server can bind its ports again.
        $launcher = 'command -v setsid >/dev/null 2>&1 || exit 127; for fd in /proc/$$/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-" 2>/dev/null; done;'
            . ' nohup setsid "$0" "$1" "$2" >> "$3" 2>&1 < /dev/null &';
        $process = @proc_open(['/bin/bash','-c',$launcher, PHP_BINARY, self::root() . '/server/bin/update.php', '--tag='.$tag, self::root() . '/server/storage/update.log'],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes, self::root());
        if (!is_resource($process) || proc_close($process) !== 0) {
            $status = ['state'=>'failed','tag'=>$tag,'updated_at'=>gmdate('c'),'message'=>'Could not start the updater (bash and setsid are required).'];
            self::writeStatus($status);
            throw new InvalidArgumentException($status['message']);
        }
        return self::status();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function writeStatus(array $status): void { self::writeJson(self::STATUS, $status); }

    public static function validTag(string $tag): bool { return preg_match(self::TAG, $tag) === 1; }

    /** @return array{tag: string|null, commit: string} */
    private static function current(string $root): array
    {
        $commit = trim(self::capture(['git','rev-parse','HEAD'], $root));
        $tag = trim(self::capture(['git','describe','--tags','--exact-match','--match','v[0-9]*','HEAD'], $root));
        return ['tag'=>self::validTag($tag) ? $tag : null, 'commit'=>preg_match('/^[0-9a-f]{40}$/',$commit) ? $commit : ''];
    }

    /** @return array{tag: string, name: string, url: string, body: string, published_at: string} */
    private static function cleanRelease(array $value): array
    {
        $tag = is_string($value['tag'] ?? null) ? $value['tag'] : '';
        if (!self::validTag($tag)) throw new InvalidArgumentException('The release has an invalid version tag.');
        $text = static fn (mixed $item, int $limit): string => is_string($item) ? substr($item, 0, $limit) : '';
        $url = $text($value['url'] ?? null, 500);
        if (!preg_match('#^https://github\.com/#', $url)) $url = 'https://github.com/'.self::REPOSITORY.'/releases/tag/'.rawurlencode($tag);
        return ['tag'=>$tag, 'name'=>$text($value['name'] ?? null, 200) ?: $tag, 'url'=>$url, 'body'=>$text($value['body'] ?? null, 20000), 'published_at'=>$text($value['published_at'] ?? null, 64)];
    }

    private static function readJson(string $file): mixed
    {
        $raw = @file_get_contents(self::root() . '/server/' . $file);
        return $raw === false ? null : json_decode($raw, true);
    }

    private static function writeJson(string $file, array $value): void
    {
        $path = self::root() . '/server/' . $file;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0770, true);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        rename($temporary, $path);
    }

    private static function clean(string $root): bool
    {
        return self::succeeds(['git','diff','--quiet','HEAD'],$root)
            && self::succeeds(['git','diff','--cached','--quiet'],$root)
            && trim(self::capture(['git','status','--porcelain','--untracked-files=no'],$root)) === '';
    }

    private static function compareTags(string $left, string $right): int
    {
        $parse = static function(string $tag): array { preg_match('/^v([0-9]+)\.([0-9]+)\.([0-9]+)/',$tag,$m); return [(int)$m[1],(int)$m[2],(int)$m[3]]; };
        return $parse($left) <=> $parse($right);
    }

    private static function succeeds(array $command, string $cwd): bool
    {
        $pipes=[]; $process=@proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
        if (!is_resource($process)) return false;
        fclose($pipes[1]); fclose($pipes[2]); return proc_close($process) === 0;
    }

    private static function capture(array $command, string $cwd): string
    {
        $pipes=[]; $process=@proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
        if (!is_resource($process)) return '';
        $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($process) === 0 ? $output : '';
    }

    private static function root(): string { return dirname(__DIR__,3); }
}
