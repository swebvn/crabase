<?php

namespace App\Service;

use App\Record\{MessageRole, NewMessage, User};
use InvalidArgumentException;

/** Chat threads: creation, titles, messages, the shared model choice, archive, cancellation and approvals. */
final class Chats
{
    public static function create(array $data): array
    {
        $project = empty($data['project_id']) ? null : Input::text($data['project_id'], 64);
        if ($project !== null && !Store::connection()->table('projects')->where('id', $project)->exists()) {
            throw new InvalidArgumentException('Project not found.');
        }
        $id = bin2hex(random_bytes(8));
        $now = gmdate('c');
        Store::connection()->table('chats')->insert([
            'id' => $id, 'project_id' => $project,
            'title' => Input::text($data['title'] ?? 'New chat', 512),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Store::event($id, 'Started a new thread');
        return ['id' => $id];
    }

    public static function rename(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        if (!Store::connection()->table('chats')->where('id', $id)->exists()) throw new InvalidArgumentException('Conversation not found.');
        Store::connection()->table('chats')->where('id', $id)->update(['title' => Input::text($data['title'] ?? null, 512)]);
        return ['ok' => true];
    }

    public static function send(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        $chat = Store::connection()->table('chats')->where('id', $id)->first() ?? throw new InvalidArgumentException('Conversation not found.');
        $body = $data['body'] ?? '';
        if (!is_string($body) || strlen($body) > 20000) throw new InvalidArgumentException('Message must be at most 20000 characters.');
        $body = trim($body);
        $user = Store::connection()->table('users')->into(User::class)->find(Input::text($data['user_id'] ?? null, 64)) ?? throw new InvalidArgumentException('User not found.');
        $author = $user->name;
        $pending = Attachments::pending($data['attachments'] ?? [], $user->id);
        if ($body === '' && !$pending) throw new InvalidArgumentException('Enter a message or attach a file.');
        $mode = $data['mode'] ?? 'note';
        if (!in_array($mode, ['note','agent'])) {
            throw new InvalidArgumentException('Unknown message mode.');
        }
        if ($chat['archived']) {
            throw new InvalidArgumentException('Restore this thread before sending a message.');
        }
        $options = $mode === 'agent' ? ModelCatalog::options($data) : ['model' => null,'effort' => null];
        $attachments = Attachments::move($pending, $id);
        try { Store::transaction(function () use ($chat, $id, $body, $user, $author, $mode, $options, $attachments) {
            $db = Store::connection();
            $message = new NewMessage($id, $mode === 'note' ? MessageRole::Note : MessageRole::User, $author, $body, new \DateTimeImmutable(), $user->id, $attachments);
            $messageId = $db->table('messages')->insert($message);
            $db->table('uploads')->whereIn('id', array_column($attachments, 'id'))->delete();
            $db->table('chats')->where('id', $id)->update(['updated_at' => gmdate('c')]);
            if ($mode === 'agent') {
                $db->table('jobs')->insert(['chat_id' => $id, 'prompt' => $body, 'model' => $options['model'], 'effort' => $options['effort'], 'message_id'=>$messageId]);
                if ($options['model'] !== null) $db->table('chats')->where('id', $id)->update(['model' => $options['model'], 'effort' => $options['effort']]);
                $db->table('chats')->where('id', $id)->where('status', 'idle')->update(['status' => 'queued']);
            }
            Store::event($id, $author . ($mode === 'agent' ? ' asked '.Store::agentName() : ' added a note'));
        }); } catch (\Throwable $e) { Attachments::restore($attachments, $id); throw $e; }
        return ['ok' => true];
    }

    /** The composer's model and reasoning level are stored on the chat, shared by everyone in it. */
    public static function saveModel(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        if (!Store::connection()->table('chats')->where('id', $id)->exists()) throw new InvalidArgumentException('Conversation not found.');
        if (($data['model'] ?? null) === null) throw new InvalidArgumentException('Select a model.');
        $options = ModelCatalog::options($data);
        Store::notify(Store::connection()->table('chats')->where('id', $id)->update(['model' => $options['model'], 'effort' => $options['effort']]));
        return $options;
    }

    public static function archive(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        $chat = Store::connection()->table('chats')->where('id', $id)->first() ?? throw new InvalidArgumentException('Conversation not found.');
        if ($chat['status'] !== 'idle') {
            throw new InvalidArgumentException('Stop the active work before archiving.');
        }
        Store::connection()->table('chats')->where('id', $id)->update(['archived' => empty($data['archived']) ? 0 : 1]);
        return ['ok' => true];
    }

    public static function cancel(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        if (!Store::connection()->table('chats')->where('id', $id)->exists()) throw new InvalidArgumentException('Conversation not found.');
        Store::connection()->table('jobs')->where('chat_id', $id)->whereIn('status', ['queued','running'])->update(['cancel' => 1]);
        return ['ok' => true];
    }

    public static function decideApproval(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        if (!Store::connection()->table('chats')->where('id', $id)->exists()) throw new InvalidArgumentException('Conversation not found.');
        if (!in_array($data['decision'] ?? '', ['accept','decline'])) {
            throw new InvalidArgumentException('Invalid approval decision.');
        }
        if (!is_int($data['approval_id'] ?? null)) throw new InvalidArgumentException('Invalid approval.');
        if (!Store::connection()->table('approvals')->where('id', $data['approval_id'])->where('chat_id', $id)->whereNull('decision')->update(['decision' => $data['decision']])) {
            throw new InvalidArgumentException('This approval was already decided.');
        }
        Store::event($id, $data['decision'] === 'accept' ? 'Approved an agent action' : 'Declined an agent action');
        return ['ok' => true];
    }
}
