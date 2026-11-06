<?php

declare(strict_types=1);

namespace App\Process;

use App\Service\{Auth, ProjectAccess, Store};
use Crab\Engine\Network\WriteResult;
use Crab\Engine\WebSocket\WebSocketSession;

/**
 * Connected browser tabs: session token, open chat, last pushed state/thread and typing presence.
 * Pushes `patch` diffs when the workspace revision changes. Nothing here is persisted.
 */
final class WorkspaceClients
{
    /** @var array<string, array{token: ?string, chat_id: ?string, state: ?array, thread: ?array, user: string, typing: float}> */
    private array $clients = [];
    /** @var array<string, WebSocketSession> */
    private array $connections = [];
    private string $revision = '';

    public function add(WebSocketSession $connection, ?string $token): void
    {
        $id = self::id($connection);
        $this->connections[$id] = $connection;
        $this->clients[$id] = ['token' => $token, 'chat_id' => null, 'state' => null, 'thread' => null, 'user' => '', 'typing' => 0.0];
    }

    public function remove(WebSocketSession $connection): void
    {
        $id = self::id($connection);
        $chatId = $this->clients[$id]['chat_id'] ?? null;
        unset($this->clients[$id], $this->connections[$id]);
        $this->presence($chatId);
    }

    public function has(WebSocketSession $connection): bool
    {
        return isset($this->clients[self::id($connection)]);
    }

    public function any(): bool
    {
        return $this->clients !== [];
    }

    public function actor(WebSocketSession $connection): ?array
    {
        return Auth::user($this->clients[self::id($connection)]['token'] ?? null);
    }

    public function chatId(WebSocketSession $connection): ?string
    {
        return $this->clients[self::id($connection)]['chat_id'] ?? null;
    }

    /** Workspace state as this user may see it, plus their pins. */
    public static function state(array $actor, ?array $snapshot = null): array
    {
        $state = ProjectAccess::snapshot($snapshot ?? Store::snapshot(), $actor);
        $state['pins'] = Store::connection()->table('project_pins')->where('user_id', $actor['id'])->whereIn('project_id', array_column($state['projects'], 'id'))->pluck('project_id');
        return $state;
    }

    /**
     * Records what a sync sent so later patches diff against it. An older history page ($older) extends the
     * thread already held for the same chat instead of replacing it. Returns the chats whose presence changed.
     *
     * @return list<?string>
     */
    public function subscribe(WebSocketSession $connection, array $actor, ?string $chatId, array $state, ?array $thread, bool $older): array
    {
        $client = &$this->clients[self::id($connection)];
        $previous = $client['chat_id'];
        $same = $previous === $chatId;
        if ($older && $same && $thread && $client['thread']) {
            $thread['messages'] = array_merge($thread['messages'], $client['thread']['messages']);
        }
        $client = ['token' => $client['token'], 'chat_id' => $chatId, 'state' => $state, 'thread' => $thread, 'user' => $actor['name'], 'typing' => $same ? $client['typing'] : 0.0];
        return $same ? [] : [$previous, $chatId];
    }

    /** Returns whether the visible typing state changed. */
    public function typing(WebSocketSession $connection, bool $active): bool
    {
        $client = &$this->clients[self::id($connection)];
        $was = $client['typing'] > microtime(true);
        $client['typing'] = $active ? microtime(true) + 6 : 0.0;
        return $was !== $active;
    }

    // Presence is live connection state only: who has a chat open and who is typing.
    public function presence(?string $chatId): void
    {
        if (!$chatId) return;
        $viewers = $typing = [];
        foreach ($this->clients as $client) {
            if ($client['chat_id'] !== $chatId) continue;
            $viewers[] = $client['user'];
            if ($client['typing'] > microtime(true)) $typing[] = $client['user'];
        }
        $packet = ['type' => 'presence', 'chat_id' => $chatId, 'viewers' => array_values(array_unique($viewers)), 'typing' => array_values(array_unique($typing))];
        foreach ($this->clients as $id => $client) {
            if ($client['chat_id'] === $chatId) $this->send($id, $packet);
        }
    }

    /** Sends to viewers of a chat who can still access it. */
    public function toChat(string $chatId, array $packet): void
    {
        foreach ($this->clients as $id => $client) {
            if ($client['chat_id'] === $chatId && ($actor = Auth::user($client['token'])) && ProjectAccess::canChat($actor, $chatId)) $this->send($id, $packet);
        }
    }

    public function toAdmins(array $packet): void
    {
        foreach ($this->clients as $id => $client) {
            if (!empty(Auth::user($client['token'])['admin'])) $this->send($id, $packet);
        }
    }

    public function isOpen(WebSocketSession $connection): bool
    {
        return in_array($connection, $this->connections, true);
    }

    public function reply(WebSocketSession $connection, array $message): void
    {
        if ($connection->sendText(json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE)) !== WriteResult::Accepted) $connection->close();
    }

    public function reject(WebSocketSession $connection): void
    {
        $this->reply($connection, ['type' => 'unauthorized']);
        $connection->close();
    }

    /** Every 2 s: drop revoked sessions and expire typing from tabs that closed or slept without a final packet. */
    public function sweep(): void
    {
        foreach ($this->clients as $id => $client) {
            if (!Auth::user($client['token'])) $this->rejectId($id);
        }
        $expired = [];
        foreach ($this->clients as &$client) {
            if ($client['typing'] && $client['typing'] <= microtime(true)) {
                $client['typing'] = 0.0;
                $expired[$client['chat_id']] = true;
            }
        }
        unset($client);
        foreach (array_keys($expired) as $chatId) $this->presence((string) $chatId);
    }

    /** Pushes state and open-thread diffs to every synced client when the revision changed. */
    public function publish(): void
    {
        $revision = Store::connection()->table('settings')->where('key', 'revision')->value('value') ?? '';
        if ($revision === $this->revision) return;
        $this->revision = $revision;
        $snapshot = Store::snapshot();
        $threads = $revoked = [];
        foreach ($this->clients as $id => &$client) {
            if (!$actor = Auth::user($client['token'])) {
                $this->rejectId($id);
                continue;
            }
            if ($client['state'] === null) continue;
            $state = self::state($actor, $snapshot);
            $patch = ['type' => 'patch'];
            foreach ($state as $key => $value) {
                if ($value !== $client['state'][$key]) $patch['state'][$key] = $value;
            }
            $client['state'] = $state;
            if ($client['chat_id'] && !in_array($client['chat_id'], array_column($state['chats'], 'id'), true)) {
                $revoked[] = $client['chat_id'];
                $client['chat_id'] = null;
                $client['thread'] = null;
                $client['typing'] = 0.0;
                $patch['access_revoked'] = true;
            }
            if ($chatId = $client['chat_id']) {
                // Diff only the window this client holds: from its oldest loaded message onward.
                $from = (int) ($client['thread']['messages'][0]['id'] ?? 0);
                $thread = $threads["$chatId:$from"] ??= Store::threadPage($chatId, after: $from);
                $old = array_column($client['thread']['messages'], null, 'id');
                foreach ($thread['messages'] as $message) {
                    $before = $old[$message['id']] ?? null;
                    if ($before === $message) continue;
                    if ($before && str_starts_with($message['body'], $before['body'])) {
                        $patch['append'][] = ['id' => $message['id'], 'delta' => substr($message['body'], strlen($before['body']))];
                    } else {
                        $patch['messages'][] = $message;
                    }
                }
                if ($thread['artifacts'] !== $client['thread']['artifacts']) $patch['artifacts'] = $thread['artifacts'];
                if ($thread['approvals'] !== $client['thread']['approvals']) $patch['approvals'] = $thread['approvals'];
                $patch['chat_id'] = $chatId;
                $client['thread'] = $thread;
            }
            if (isset($patch['state']) || isset($patch['messages']) || isset($patch['append']) || isset($patch['approvals']) || isset($patch['artifacts'])) {
                $this->send($id, $patch);
            }
        }
        unset($client);
        foreach (array_unique($revoked) as $chatId) $this->presence($chatId);
    }

    private function send(int|string $id, array $packet): void
    {
        if (isset($this->connections[$id])) $this->reply($this->connections[$id], $packet);
    }

    private function rejectId(int|string $id): void
    {
        if (isset($this->connections[$id])) $this->reject($this->connections[$id]);
    }

    private static function id(WebSocketSession $connection): string
    {
        return (string) spl_object_id($connection);
    }
}
