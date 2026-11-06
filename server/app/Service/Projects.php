<?php

namespace App\Service;

use App\Record\User;
use InvalidArgumentException;

/** Project records: registering folders, per-user pins, archive and delete. Callers check project access first. */
final class Projects
{
    /** New projects are public (all signed-in users); the non-admin creator is also a member, so they keep access if an admin makes it private. */
    public static function create(array $data, ?array $actor = null): array
    {
        $path = WorkspaceFolders::resolve($data['path'] ?? null);
        $name = basename($path);
        $db = Store::connection();
        $existing = $db->table('projects')->where('path', $path)->first();
        if ($existing) {
            // Never reveal or grant access to someone else's private project through its folder path.
            if ($actor && !$actor['admin'] && !in_array($existing['id'], ProjectAccess::ids($actor), true)) {
                throw new InvalidArgumentException('This folder is already a project. Ask an administrator for access.');
            }
            return ['id'=>$existing['id']];
        }
        $id = bin2hex(random_bytes(8));
        Store::transaction(function () use ($id, $name, $path, $actor): void {
            Store::connection()->table('projects')->insert(['id' => $id, 'name' => $name, 'path' => $path, 'visibility' => 'public']);
            if ($actor && !$actor['admin']) Store::connection()->table('project_members')->insert(['project_id' => $id, 'user_id' => $actor['id']]);
        });
        Store::event(null, "Added project $name");
        return ['id' => $id];
    }

    public static function pin(array $data): array
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

    public static function manage(string $action, array $data): array
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
        Store::transaction(function () use ($project, $ids) {
            $db = Store::connection();
            foreach (['approvals', 'jobs', 'messages', 'events'] as $table) $db->table($table)->whereIn('chat_id', $ids)->delete();
            $db->table('chats')->whereIn('id', $ids)->delete();
            $db->table('projects')->where('id', $project['id'])->delete();
        });
        return ['ok' => true];
    }
}
