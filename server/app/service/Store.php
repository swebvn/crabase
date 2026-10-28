<?php

declare(strict_types=1);

namespace app\service;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Database\DatabaseManager;
use InvalidArgumentException;
use app\model\{Approval, Chat, Event, Job, Message, Project, Setting, User};

final class Store
{
    private static ?DatabaseManager $manager = null;
    private static ?Connection $transaction = null;

    public static function useManager(DatabaseManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function db(): Database|Connection
    {
        return self::connection();
    }

    public static function connection(): Database|Connection
    {
        if (self::$transaction !== null) return self::$transaction;
        $manager = self::$manager ??= self::manager();
        $db = $manager->connection();
        if (!$db->table('sqlite_master')->where('type', 'table')->where('name', 'phinxlog')->exists()) {
            throw new \RuntimeException('Database needs migrations. Run: cd server && vendor/bin/phinx migrate');
        }
        return $db;
    }

    public static function transaction(\Closure $callback): mixed
    {
        $db = self::connection();
        return $db->transaction(function (Connection $connection) use ($callback): mixed {
            $previous = self::$transaction;
            self::$transaction = $connection;
            try { return $callback($connection); }
            finally { self::$transaction = $previous; }
        });
    }

    public static function all(string $sql, array $args = []): array
    {
        return self::connection()->select($sql, $args);
    }

    public static function run(string $sql, array $args = [], bool $notify = true): void
    {
        $affected = self::connection()->execute($sql, $args);
        if ($affected && $notify) self::notify($affected);
    }

    public static function notify(int $affected = 1): void
    {
        if (!$affected) return;
        self::connection()->execute("INSERT INTO settings VALUES ('revision','1') ON CONFLICT(key) DO UPDATE SET value=CAST(value AS INTEGER)+1");
    }

    public static function text(mixed $value, int $max = 20000): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max) throw new InvalidArgumentException("Enter between 1 and $max characters.");
        return trim($value);
    }

    public static function event(?string $chat, string $label): void
    {
        Event::query()->create(['chat_id' => $chat, 'label' => $label, 'created_at' => gmdate('c')]);
        self::notify();
    }

    public static function thread(string $id): array
    {
        $chat = Chat::find($id)?->toArray();
        if (!$chat) throw new InvalidArgumentException('Conversation not found.');
        return ['artifacts' => Artifacts::listing($id), 'chat' => $chat, 'messages' => Message::query()->where('chat_id', $id)->orderBy('id')->get()->toArray(), 'approvals' => Approval::query()->where('chat_id', $id)->whereNull('decision')->get()->toArray()];
    }

    public static function threadPage(string $id, ?int $before = null, ?int $after = null, int $limit = 150): array
    {
        $chat = Chat::find($id)?->toArray();
        if (!$chat) throw new InvalidArgumentException('Conversation not found.');
        $query = Message::query()->where('chat_id', $id);
        if ($before !== null) $query->where('id', '<', $before)->orderByDesc('id');
        elseif ($after !== null) $query->where('id', '>=', $after)->orderBy('id');
        else $query->orderByDesc('id');
        $messages = ($after !== null ? $query : $query->limit($limit + 1))->get()->toArray();
        $hasMore = $after === null && count($messages) > $limit;
        if ($after === null) $messages = array_slice($messages, 0, $limit);
        if (($before === null && $after === null) || $before !== null) $messages = array_reverse($messages);
        $thread = ['artifacts' => Artifacts::listing($id), 'chat' => $chat, 'messages' => $messages, 'approvals' => Approval::query()->where('chat_id', $id)->whereNull('decision')->get()->toArray()];
        $thread['pagination'] = ['has_more' => $hasMore, 'oldest_id' => $messages ? (int)$messages[0]['id'] : null];
        return $thread;
    }

    public static function agentName(): string
    {
        $config = require dirname(__DIR__, 2).'/config/crabase.php';
        return trim((string)$config['agent_name']) ?: 'Crab';
    }

    public static function snapshot(): array
    {
        $chats = Chat::query()->from('chats as c')->select('c.*', 'p.name as project_name')
            ->selectSub(Message::query()->selectRaw('json_group_array(DISTINCT author)')->whereColumn('chat_id', 'c.id')->whereIn('role', ['user','note']), 'participants')
            ->selectSub(Job::query()->from('jobs as j')->join('messages as m', 'm.id', '=', 'j.message_id')->select('m.author')->whereColumn('j.chat_id', 'c.id')->where('j.status', 'running')->orderBy('j.id')->limit(1), 'running_by')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')->orderByDesc('c.updated_at')->orderByDesc('c.rowid')->get()->toArray();
        foreach ($chats as &$chat) $chat['participants'] = json_decode($chat['participants'] ?? '[]', true) ?: [];
        unset($chat);
        $users = User::query()->from('users as u')->leftJoin('accounts as a', 'a.user_id', '=', 'u.id')->orderBy('u.name')->get(['u.id','u.name','u.avatar_url','u.created_at','a.email'])->toArray();
        foreach ($users as &$user) { if (!$user['avatar_url'] && $user['email']) $user['avatar_url'] = Auth::avatar($user['email']); unset($user['email']); }
        unset($user);
        return ['users' => $users, 'agentName' => self::agentName(), 'models' => ModelCatalog::all(), 'projects' => Project::query()->select('*')->selectRaw('rowid AS created_order')->get()->toArray(), 'chats' => $chats, 'events' => Event::query()->from('events as e')->leftJoin('chats as c', 'c.id', '=', 'e.chat_id')->orderByDesc('e.id')->limit(50)->get(['e.*','c.title'])->toArray(), 'runtime' => Setting::query()->whereKey('runtime')->value('value') ?? 'offline'];
    }

    private static function manager(): DatabaseManager
    {
        $config = require dirname(__DIR__, 2).'/config/crabase.php';
        return new DatabaseManager(['sqlite' => ['driver' => 'sqlite', 'database' => $config['database_path'], 'pool' => 1, 'timeout' => 5]], 'sqlite');
    }
}
