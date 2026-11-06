<?php

declare(strict_types=1);

namespace App\Process;

use Crab\Engine\EventLoop;

/** Event-loop read/write watchers keyed by stream, so each stream has at most one of each. */
final class StreamWatchers
{
    /** @var array<int, array{read?: string, write?: string}> */
    private array $watchers = [];

    private ?EventLoop $loop = null;

    public function attach(EventLoop $loop): void
    {
        $this->loop = $loop;
    }

    public function readable(mixed $stream, callable $callback): void
    {
        if ($this->loop === null || !is_resource($stream)) return;
        $this->unwatchReadable($stream);
        $this->watchers[get_resource_id($stream)]['read'] = $this->loop->onReadable($stream, $callback(...));
    }

    public function writable(mixed $stream, callable $callback): void
    {
        if ($this->loop === null || !is_resource($stream)) return;
        $this->unwatchWritable($stream);
        $this->watchers[get_resource_id($stream)]['write'] = $this->loop->onWritable($stream, $callback(...));
    }

    public function unwatchReadable(mixed $stream): void
    {
        $this->cancel($stream, 'read');
    }

    public function unwatchWritable(mixed $stream): void
    {
        $this->cancel($stream, 'write');
    }

    public function cancelAll(): void
    {
        foreach ($this->watchers as $watchers) {
            foreach ($watchers as $id) $this->loop?->cancel($id);
        }
        $this->watchers = [];
        $this->loop = null;
    }

    private function cancel(mixed $stream, string $kind): void
    {
        if ($this->loop === null || !is_resource($stream)) return;
        $key = get_resource_id($stream);
        $id = $this->watchers[$key][$kind] ?? null;
        if ($id !== null) {
            $this->loop->cancel($id);
            unset($this->watchers[$key][$kind]);
        }
        if (($this->watchers[$key] ?? []) === []) unset($this->watchers[$key]);
    }
}
