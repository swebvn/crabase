<?php

declare(strict_types=1);

namespace app\process;

use app\service\Auth;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Servers;
use Crab\Contracts\Workers;
use Crab\Engine\Crab;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Engine\Http\Request;
use Crab\Http\Request as HttpRequest;
use Crab\Contracts\WorkerProvider;
use Crab\Contracts\Log\Logger;

/** Registers the persistent Codex worker as a kept Crab server. */
final class CodexServer implements WorkerProvider
{
    private ?Codex $codex = null;
    private ?LegacyWorker $worker = null;

    public function register(Container $container): void
    {
        $servers = $container->get(Servers::class);
        $host = (string) config('crabase.bind_host', '127.0.0.1');
        $servers->add('codex', function () use ($host): array {
            return [new Workers(
                'codex',
                workers: 1,
                setup: function (Crab $group) use ($host): void {
                    $group->listen('tcp://'.$host.':8788', new WebSocketServer(
                        function (WebSocketSession $session, string $message, MessageType $type): void { $this->message($session, $message, $type); },
                        function (WebSocketSession $session, Request $request): void { $this->open($session, $request); },
                        function (WebSocketSession $session, int $code, string $reason): void { $this->close($session); },
                        accept: fn(Request $request): bool => $this->accept($request),
                    ));
                },
            )];
        }, runs: 'Codex WebSocket on :8788', grace: 30.0, reloads: false);
    }

    public function start(\Crab\Engine\Process\Worker $worker, Container $container): void
    {
        if ($worker->group->name !== 'codex') {
            return;
        }
        $this->worker = new LegacyWorker($worker->loop);
        $this->codex = new Codex($container->get(Logger::class));
        $this->codex->onWorkerStart($this->worker);
    }

    public function stop(\Crab\Engine\Process\Worker $worker, Container $container): void
    {
        if ($worker->group->name !== 'codex') {
            return;
        }
        $this->codex?->onWorkerStop();
        $this->codex = null;
        $this->worker = null;
    }

    private function accept(Request $request): bool
    {
        $host = explode(':', (string) $request->header('Host'))[0];
        if (!Auth::allowedHost($host)) return false;
        return true;
    }

    private function open(WebSocketSession $session, Request $request): void
    {
        if ($this->codex === null || $this->worker === null) {
            $session->close(1011, 'Codex worker is unavailable');
            return;
        }
        $connection = new LegacyConnection($session);
        $this->worker->connections[$connection->id] = $connection;
        $this->codex->onWebSocketConnect($connection, new LegacyRequest(HttpRequest::fromEngine($request)));
    }

    private function message(WebSocketSession $session, string $message, MessageType $type): void
    {
        if ($this->codex === null || $this->worker === null) return;
        foreach ($this->worker->connections as $connection) {
            if ($connection->session !== $session) continue;
            $this->codex->onMessage($connection, $message);
            return;
        }
    }

    private function close(WebSocketSession $session): void
    {
        if ($this->codex === null || $this->worker === null) return;
        foreach ($this->worker->connections as $connection) {
            if ($connection->session !== $session) continue;
            $this->codex->onClose($connection);
            unset($this->worker->connections[$connection->id]);
            return;
        }
    }
}
