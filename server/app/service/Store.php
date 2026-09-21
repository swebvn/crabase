<?php

namespace app\service;

use PDO;
use InvalidArgumentException;
use app\model\{Chat, Message, Approval, Project, Event, Setting, User};

final class Store
{
    public static function db(): PDO
    {
        if (!config('database')) {
            \Webman\Config::load(dirname(__DIR__, 2).'/config', ['route']);
        }
        $db = \support\Db::connection()->getPdo();
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='phinxlog'")->fetchColumn()) {
            throw new \RuntimeException('Database needs migrations. Run: cd server && vendor/bin/phinx migrate');
        }
        return $db;
    }

    public static function all(string $sql, array $args = []): array
    {
        $s = self::db()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll();
    }
    public static function run(string $sql, array $args = [], bool $notify = true): void
    {
        $s = self::db()->prepare($sql);
        $s->execute($args);
        if ($s->rowCount() && $notify) {
            self::notify();
        }
    }
    public static function notify(int $affected = 1): void
    {
        if (!$affected) return;
        self::db()->exec("INSERT INTO settings VALUES ('revision','1') ON CONFLICT(key) DO UPDATE SET value=CAST(value AS INTEGER)+1");
    }
    public static function text(mixed $value, int $max = 20000): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max) {
            throw new InvalidArgumentException("Enter between 1 and $max characters.");
        }
        return trim($value);
    }
    public static function event(?string $chat, string $label): void
    {
        self::db();
        Event::query()->create(['chat_id' => $chat, 'label' => $label, 'created_at' => gmdate('c')]);
        self::notify();
    }
    public static function thread(string $id): array
    {
        self::db();
        $chat = Chat::query()->find($id)?->toArray();
        if (!$chat) {
            throw new InvalidArgumentException('Conversation not found.');
        }
        return ['artifacts' => Artifacts::listing($id), 'chat' => $chat, 'messages' => Message::query()->where('chat_id', $id)->orderBy('id')->get()->toArray(), 'approvals' => Approval::query()->where('chat_id', $id)->whereNull('decision')->get()->toArray()];
    }
    public static function threadPage(string $id, ?int $before = null, ?int $after = null, int $limit = 150): array
    {
        self::db();
        $chat = Chat::query()->find($id)?->toArray();
        if (!$chat) throw new InvalidArgumentException('Conversation not found.');
        $query = Message::query()->where('chat_id', $id);
        if ($before !== null) $query->where('id', '<', $before)->orderByDesc('id');
        elseif ($after !== null) $query->where('id', '>=', $after)->orderBy('id');
        else $query->orderByDesc('id');
        $messages = ($after !== null ? $query : $query->limit($limit + 1))->get()->toArray();
        $hasMore = $after === null && count($messages) > $limit;
        if ($after === null) $messages = array_slice($messages, 0, $limit);
        if ($before === null && $after === null || $before !== null) $messages = array_reverse($messages);
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
        self::db();
        $chats = Chat::query()->from('chats as c')->select('c.*', 'p.name as project_name')
            ->selectSub(Message::query()->selectRaw('json_group_array(DISTINCT author)')->whereColumn('chat_id', 'c.id')->whereIn('role', ['user','note']), 'participants')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')->orderByDesc('c.updated_at')->orderByDesc('c.rowid')->get()->toArray();
        foreach ($chats as &$chat) {
            $chat['participants'] = json_decode($chat['participants'], true);
        }
        unset($chat);
        $users = User::query()->from('users as u')->leftJoin('accounts as a', 'a.user_id', '=', 'u.id')
            ->orderBy('u.name')->get(['u.id','u.name','u.avatar_url','u.created_at','a.email'])->toArray();
        foreach ($users as &$user) {
            if (!$user['avatar_url'] && $user['email']) $user['avatar_url'] = Auth::avatar($user['email']);
            unset($user['email']);
        }
        unset($user);
        return [
            'users' => $users,
            'agentName' => self::agentName(),
            'models' => json_decode(Setting::query()->whereKey('models')->value('value') ?? '[]', true),
            'projects' => Project::query()->select('*')->selectRaw('rowid AS created_order')->get()->toArray(),
            'chats' => $chats,
            'events' => Event::query()->from('events as e')->leftJoin('chats as c', 'c.id', '=', 'e.chat_id')->orderByDesc('e.id')->limit(50)->get(['e.*','c.title'])->toArray(),
            'runtime' => Setting::query()->whereKey('runtime')->value('value') ?? 'offline',
        ];
    }
}
