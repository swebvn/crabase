<?php

namespace app\service;

use InvalidArgumentException;
use app\record\{MessageRole, NewMessage, User};

final class Actions
{
    public static function agentOptions(array $data): array
    {
        $model = $data['model'] ?? null;
        $effort = $data['effort'] ?? null;
        if ($effort === 'ultra') throw new InvalidArgumentException('Ultra reasoning is disabled.');
        if ($model === null) {
            if ($effort !== null) {
                throw new InvalidArgumentException('Select a model before choosing reasoning.');
            }
            return ['model' => null,'effort' => null];
        }
        $model = Input::text($model, 200);
        $models = ModelCatalog::all();
        $selected = array_values(array_filter($models, fn ($entry) => $entry['model'] === $model))[0] ?? null;
        if (!$selected) {
            throw new InvalidArgumentException('This model is not in the current Codex catalog. Refresh the model list.');
        }
        $efforts = array_values(array_filter(array_column($selected['supportedReasoningEfforts'], 'reasoningEffort'), fn ($level) => $level !== 'ultra'));
        if (!$efforts) {
            if ($effort !== null) {
                throw new InvalidArgumentException('This model does not offer reasoning levels.');
            }
            return ['model' => $model,'effort' => null];
        }
        $default = $selected['defaultReasoningEffort'] ?? null;
        $effort ??= in_array($default, $efforts, true) ? $default : ($efforts[0] ?? null);
        if ($effort !== null && !in_array($effort, $efforts, true)) {
            throw new InvalidArgumentException('This reasoning level is not supported by the selected model.');
        }
        return ['model' => $model,'effort' => $effort];
    }
    public static function handle(string $action, array $data): array
    {
        Store::db();
        if (in_array($action, ['uploadStart', 'uploadChunk', 'uploadRemove'], true)) return Attachments::handle($action, $data);
        $result = match ($action) {
            'projectFolders' => WorkspaceFolders::listing($data),
            'projectContext' => self::projectContext($data),
            'avatarSave' => Attachments::saveAvatar($data),
            'projectWorkspace' => ProjectWorkspace::snapshot($data),
            'projectFile' => ProjectWorkspace::file($data),
            'projectDiff' => ProjectWorkspace::diff($data),
            'projectSave' => ProjectWorkspace::save($data),
            'projectPin' => self::pinProject($data),
            'project' => self::createProject($data),
            'worktreeCreate' => Worktrees::create($data),
            'projectArchive', 'projectDelete' => self::manageProject($action, $data),
            'create' => self::createChat($data),
            'rename' => self::renameChat($data),
            'message' => self::sendMessage($data),
            'archive' => self::archiveChat($data),
            'cancel' => self::cancelJobs($data),
            'approval' => self::decideApproval($data),
            'userAvatar' => self::updateUserAvatar($data),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
        if (!in_array($action, ['projectContext', 'projectFolders', 'projectWorkspace', 'projectFile', 'projectDiff'], true)) {
            Store::notify();
        }
        return $result;
    }

    public static function projectContext(array $data): array
    {
        return ProjectWorkspace::context($data);
    }


    public static function createProject(array $data): array
    {
        $path = WorkspaceFolders::resolve($data['path'] ?? null);
        $name = basename($path);
        $db = Store::connection();
        $existing = $db->table('projects')->where('path', $path)->first();
        if ($existing) return ['id'=>$existing['id']];
        $id = bin2hex(random_bytes(8));
        $db->table('projects')->insert(['id' => $id, 'name' => $name, 'path' => $path]);
        Store::event(null, "Added project $name");
        return ['id' => $id];
    }

    public static function pinProject(array $data): array
    {
        $id = Input::text($data['project_id'] ?? null, 64);
        if (!is_bool($data['pinned'] ?? null)) throw new InvalidArgumentException('Pinned must be a boolean.');
        $user = Store::connection()->table('users')->into(User::class)->find(Input::text($data['user_id'] ?? null, 64)) ?? throw new InvalidArgumentException('User not found.');
        if (!Store::connection()->table('projects')->where('id', $id)->exists()) throw new InvalidArgumentException('Project not found.');
        if ($data['pinned']) {
            Store::connection()->table('project_pins')->upsert([['user_id' => $user->id, 'project_id' => $id]], ['user_id', 'project_id'], []);
        } else {
            Store::connection()->table('project_pins')->where('user_id', $user->id)->where('project_id', $id)->delete();
        }
        return ['ok' => true];
    }

    public static function manageProject(string $action, array $data): array
    {
        $id = Input::text($data['project_id'] ?? null, 64);
        if ($action === 'projectArchive' && !is_bool($data['archived'] ?? null)) {
            throw new InvalidArgumentException('Archived must be a boolean.');
        }
        $force = $data['force'] ?? false;
        if (!is_bool($force)) throw new InvalidArgumentException('Force must be a boolean.');
        $project = Store::connection()->table('projects')->where('id', $id)->first() ?? throw new InvalidArgumentException('Project not found.');
        if ($action === 'projectArchive') {
            Store::connection()->table('projects')->where('id', $id)->update(['archived' => (int)$data['archived']]);
            return ['ok' => true];
        }
        $db = Store::connection();
        if ($db->table('projects')->where('parent_id', $id)->exists()) throw new InvalidArgumentException('Delete this project’s worktrees first.');
        $ids = $db->table('chats')->where('project_id', $id)->pluck('id');
        if ($db->table('chats')->where('project_id', $id)->where('status', '!=', 'idle')->exists() ||
            $db->table('jobs')->whereIn('chat_id', $ids)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new InvalidArgumentException('Stop active work in this project before deleting it.');
        }
        // Finish Git removal before opening a short database transaction. Never delete the original project folder.
        if ($project['parent_id'] && !Worktrees::remove($project, $force)) return ['requires_confirmation' => true];
        \app\service\Store::transaction(function () use ($project, $ids) {
            $db = Store::connection();
            foreach (['approvals', 'jobs', 'messages', 'events'] as $table) $db->table($table)->whereIn('chat_id', $ids)->delete();
            $db->table('chats')->whereIn('id', $ids)->delete();
            $db->table('projects')->where('id', $project['id'])->delete();
        });
        return ['ok' => true];
    }

    public static function createChat(array $data): array
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

    public static function renameChat(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        if (!Store::connection()->table('chats')->where('id', $id)->exists()) throw new InvalidArgumentException('Conversation not found.');
        Store::connection()->table('chats')->where('id', $id)->update(['title' => Input::text($data['title'] ?? null, 512)]);
        return ['ok' => true];
    }


    public static function sendMessage(array $data): array
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
        $options = $mode === 'agent' ? self::agentOptions($data) : ['model' => null,'effort' => null];
        $attachments = Attachments::move($pending, $id);
        try { \app\service\Store::transaction(function () use ($chat, $id, $body, $user, $author, $mode, $options, $attachments) {
            $db = Store::connection();
            $message = new NewMessage($id, $mode === 'note' ? MessageRole::Note : MessageRole::User, $author, $body, new \DateTimeImmutable(), $user->id, $attachments);
            $messageId = $db->table('messages')->insert($message);
            $db->table('uploads')->whereIn('id', array_column($attachments, 'id'))->delete();
            $db->table('chats')->where('id', $id)->update(['updated_at' => gmdate('c')]);
            if ($mode === 'agent') {
                $db->table('jobs')->insert(['chat_id' => $id, 'prompt' => $body, 'model' => $options['model'], 'effort' => $options['effort'], 'message_id'=>$messageId]);
                $db->table('chats')->where('id', $id)->where('status', 'idle')->update(['status' => 'queued']);
            }
            Store::event($id, $author . ($mode === 'agent' ? ' asked '.Store::agentName() : ' added a note'));
        }); } catch (\Throwable $e) { Attachments::restore($attachments, $id); throw $e; }
        return ['ok' => true];
    }

    public static function archiveChat(array $data): array
    {
        $id = Input::text($data['chat_id'] ?? null, 64);
        $chat = Store::connection()->table('chats')->where('id', $id)->first() ?? throw new InvalidArgumentException('Conversation not found.');
        if ($chat['status'] !== 'idle') {
            throw new InvalidArgumentException('Stop the active work before archiving.');
        }
        Store::connection()->table('chats')->where('id', $id)->update(['archived' => empty($data['archived']) ? 0 : 1]);
        return ['ok' => true];
    }

    public static function cancelJobs(array $data): array
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
        Store::connection()->table('approvals')->where('id', $data['approval_id'] ?? 0)->where('chat_id', $id)->whereNull('decision')->update(['decision' => $data['decision']]);
        Store::event($id, $data['decision'] === 'accept' ? 'Approved an agent action' : 'Declined an agent action');
        return ['ok' => true];
    }

    public static function updateUserAvatar(array $data): array
    {
        $user = Store::connection()->table('users')->where('id', Input::text($data['user_id'] ?? null, 64))->first() ?? throw new InvalidArgumentException('User not found.');
        $url = Input::text($data['avatar_url'] ?? null, 4096);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?: ''), ['http','https'], true)) {
            throw new InvalidArgumentException('Enter an HTTP or HTTPS image URL.');
        }
        Store::connection()->table('users')->where('id', $user['id'])->update(['avatar_url' => $url]);
        return ['ok' => true];
    }
}
