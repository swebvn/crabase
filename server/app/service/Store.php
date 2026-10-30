<?php

declare(strict_types=1);

namespace app\service;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Database\DatabaseManager;
use InvalidArgumentException;
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



    public static function event(?string $chat, string $label): void
    {
        self::connection()->table('events')->insert(['chat_id' => $chat, 'label' => $label, 'created_at' => gmdate('c')]);
        self::notify();
    }

    public static function thread(string $id): array
    {
        $db = self::connection();
        $chat = $db->table('chats')->where('id', $id)->first();
        if (!$chat) throw new InvalidArgumentException('Conversation not found.');
        return ['artifacts' => Artifacts::listing($id), 'chat' => $chat, 'messages' => self::messages($db->table('messages')->where('chat_id', $id)->orderBy('id')->get()), 'approvals' => $db->table('approvals')->where('chat_id', $id)->whereNull('decision')->get()];
    }

    public static function threadPage(string $id, ?int $before = null, ?int $after = null, int $limit = 150): array
    {
        $db = self::connection();
        $chat = $db->table('chats')->where('id', $id)->first();
        if (!$chat) throw new InvalidArgumentException('Conversation not found.');
        $query = $db->table('messages')->where('chat_id', $id);
        if ($before !== null) $query->where('id', '<', $before)->orderByDesc('id');
        elseif ($after !== null) $query->where('id', '>=', $after)->orderBy('id');
        else $query->orderByDesc('id');
        $messages = self::messages(($after !== null ? $query : $query->limit($limit + 1))->get());
        $hasMore = $after === null && count($messages) > $limit;
        if ($after === null) $messages = array_slice($messages, 0, $limit);
        if (($before === null && $after === null) || $before !== null) $messages = array_reverse($messages);
        $thread = ['artifacts' => Artifacts::listing($id), 'chat' => $chat, 'messages' => $messages, 'approvals' => $db->table('approvals')->where('chat_id', $id)->whereNull('decision')->get()];
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
        $db = self::connection();
        $chats = $db->table('chats as c')->select('c.*', 'p.name as project_name')
            ->selectSub($db->table('messages')->selectRaw('json_group_array(DISTINCT author)')->whereColumn('chat_id', 'c.id')->whereIn('role', ['user','note']), 'participants')
            ->selectSub($db->table('jobs as j')->join('messages as m', 'm.id', '=', 'j.message_id')->select('m.author')->whereColumn('j.chat_id', 'c.id')->where('j.status', 'running')->orderBy('j.id')->limit(1), 'running_by')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')->orderByDesc('c.updated_at')->orderByDesc('c.rowid')->get();
        foreach ($chats as &$chat) $chat['participants'] = json_decode($chat['participants'] ?? '[]', true) ?: [];
        unset($chat);
        $users = $db->table('users as u')->leftJoin('accounts as a', 'a.user_id', '=', 'u.id')->select('u.id','u.name','u.avatar_url','u.created_at','a.email')->orderBy('u.name')->get();
        foreach ($users as &$user) { if (!$user['avatar_url'] && $user['email']) $user['avatar_url'] = Auth::avatar($user['email']); unset($user['email']); }
        unset($user);
        return ['users' => $users, 'agentName' => self::agentName(), 'models' => ModelCatalog::all(), 'projects' => $db->table('projects')->select('*')->selectRaw('rowid AS created_order')->get(), 'chats' => $chats, 'events' => $db->table('events as e')->leftJoin('chats as c', 'c.id', '=', 'e.chat_id')->select('e.*','c.title')->orderByDesc('e.id')->limit(50)->get(), 'runtime' => $db->table('settings')->where('key', 'runtime')->value('value') ?? 'offline'];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function messages(array $rows): array
    {
        foreach ($rows as &$row) {
            if (isset($row['attachments']) && is_string($row['attachments'])) {
                $row['attachments'] = json_decode($row['attachments'], true) ?: [];
            }
        }
        unset($row);
        return $rows;
    }

    private static function manager(): DatabaseManager
    {
        $config = require dirname(__DIR__, 2).'/config/crabase.php';
        return new DatabaseManager(['sqlite' => ['driver' => 'sqlite', 'database' => $config['database_path'], 'pool' => 1, 'timeout' => 5]], 'sqlite');
    }
}
