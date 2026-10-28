<?php

declare(strict_types=1);

namespace app\process;

use Crab\Engine\EventLoop;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Contracts\Http\Request;

final class LegacyWorker
{
    /** @var array<string, LegacyConnection> */
    public array $connections = [];

    public function __construct(public readonly ?EventLoop $loop = null) {}

    /** @var array<int, array{read?: string, write?: string}> */
    private array $watchers = [];

    public function onReadable(mixed $stream, callable $callback): void
    {
        if ($this->loop === null) return;
        $key = get_resource_id($stream);
        $this->watchers[$key]['read'] = $this->loop->onReadable($stream, $callback(...));
    }

    public function onWritable(mixed $stream, callable $callback): void
    {
        if ($this->loop === null) return;
        $key = get_resource_id($stream);
        $this->watchers[$key]['write'] = $this->loop->onWritable($stream, $callback(...));
    }

    public function offReadable(mixed $stream): void
    {
        $this->cancelWatcher($stream, 'read');
    }

    public function offWritable(mixed $stream): void
    {
        $this->cancelWatcher($stream, 'write');
    }

    private function cancelWatcher(mixed $stream, string $kind): void
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

class LegacyConnection
{
    public string $id = '';
    public int $maxSendBufferSize = 8_388_608;
    public mixed $onBufferFull = null;

    public function __construct(public readonly WebSocketSession $session)
    {
        $this->id = bin2hex(random_bytes(8));
    }

    public function send(string $data): void
    {
        if (!$this->session->isClosed()) $this->session->sendText($data);
    }

    public function close(): void
    {
        if (!$this->session->isClosed()) $this->session->close();
    }
}

class LegacyRequest
{
    public function __construct(private readonly Request $request) {}
    public function host(): string { return $this->request->getHeaderLine('Host'); }
    public function header(string $name): string { return $this->request->getHeaderLine($name); }
    public function cookie(string $name): ?string { return $this->request->cookie($name); }
}

final class LegacyTimer
{
    public static function add(float $interval, callable $callback): string
    {
        return EventLoop::current()->repeat($interval, static fn() => $callback());
    }
}
