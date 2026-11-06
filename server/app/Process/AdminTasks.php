<?php

declare(strict_types=1);

namespace App\Process;

use App\Service\{TokenUsage, Updater};
use Crab\Contracts\Log\Logger;
use Crab\Engine\EventLoop;
use Crab\Engine\WebSocket\WebSocketSession;

/** Short-lived child processes the worker supervises without blocking its loop: release checks, usage publishing, update progress. */
final class AdminTasks
{
    /** @var resource|null Short-lived bin/publish-usage.php run sending due token usage to Console. */
    private mixed $usagePublisher = null;
    private float $usageCheckedAt = 0.0;
    private ?array $updateStatus = null;

    public function __construct(
        private readonly EventLoop $loop,
        private readonly StreamWatchers $watchers,
        private readonly WorkspaceClients $clients,
        private readonly Logger $logger,
    ) {}

    /** Every 2 s: the detached updater only writes update.json, so push its progress to admins. */
    public function pushUpdateStatus(): void
    {
        $status = Updater::status();
        if ($this->updateStatus !== null && $status !== $this->updateStatus) $this->clients->toAdmins(['type' => 'update', 'status' => $status]);
        $this->updateStatus = $status;
    }

    public function publishTokenUsage(): void
    {
        if (is_resource($this->usagePublisher)) {
            if (proc_get_status($this->usagePublisher)['running']) return;
            proc_close($this->usagePublisher);
            $this->usagePublisher = null;
        }
        if (microtime(true) - $this->usageCheckedAt < 10 || !TokenUsage::enabled()) return;
        $this->usageCheckedAt = microtime(true);
        try {
            if (!TokenUsage::hasDue()) return;
        } catch (\Throwable $error) {
            $this->logger->error('Token usage check failed.', ['exception' => $error]);
            return;
        }
        $log = dirname(__DIR__, 2).'/runtime/logs/usage.log';
        // Close inherited worker sockets (not O_CLOEXEC) so a restart mid-send can still bind its ports.
        $launcher = 'for fd in /proc/$$/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-" 2>/dev/null; done; exec "$0" "$1"';
        $process = @proc_open(['/bin/bash', '-c', $launcher, PHP_BINARY, dirname(__DIR__, 2).'/bin/publish-usage.php'], [0=>['file','/dev/null','r'], 1=>['file',$log,'a'], 2=>['file',$log,'a']], $pipes);
        $this->usagePublisher = is_resource($process) ? $process : null;
    }

    /** Fetches the release in a child process and replies when it exits, keeping the event loop free. */
    public function updateCheck(WebSocketSession $connection, int $id): void
    {
        ChildProcess::run($this->loop, $this->watchers, [PHP_BINARY, dirname(__DIR__, 2).'/bin/release.php'], 10, 200000, function (string $output, ?string $failure) use ($connection, $id): void {
            $value = json_decode($output, true);
            try {
                if ($failure === 'timeout') throw new \InvalidArgumentException('GitHub did not respond in time. Try again later.');
                if ($failure !== null) throw new \InvalidArgumentException('The release response was too large.');
                if (!is_array($value) || !is_array($value['release'] ?? null)) throw new \InvalidArgumentException(is_string($value['error'] ?? null) ? $value['error'] : 'Could not check for releases.');
                $packet = ['id'=>$id, 'result'=>Updater::check($value['release'])];
            } catch (\InvalidArgumentException $e) {
                $packet = ['id'=>$id, 'error'=>$e->getMessage()];
            } catch (\Throwable $e) {
                $this->logger->error('Update check failed.', ['exception' => $e]);
                $packet = ['id'=>$id, 'error'=>'Could not check for releases.'];
            }
            if ($this->clients->isOpen($connection)) $this->clients->reply($connection, $packet);
        });
    }
}
