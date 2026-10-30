<?php
namespace app\service;

use app\record\{Chat, Project};
use InvalidArgumentException;

final class ProjectAccess
{
    public static function ids(array $actor): array
    {
        $db = Store::connection();
        if ($actor['admin']) return $db->table('projects')->pluck('id');
        $public = $db->table('projects')->whereNull('parent_id')->where('visibility', 'public')->pluck('id');
        $member = Store::connection()->table('projects as p')->join('project_members as m', 'm.project_id', '=', 'p.id')
            ->whereNull('p.parent_id')->where('m.user_id', $actor['id'])->pluck('p.id');
        $roots = array_values(array_unique([...$public, ...$member]));
        return $db->table('projects')->where(function ($query) use ($roots) {
            $query->whereIn('id', $roots)->orWhereIn('parent_id', $roots);
        })->pluck('id');
    }

    public static function project(array $actor, string $id): void
    {
        if (!in_array($id, self::ids($actor), true)) throw new InvalidArgumentException('Project not found or access denied.');
    }

    public static function canChat(array $actor, string $id): bool
    {
        $chat = Store::connection()->table('chats')->into(Chat::class)->find($id);
        return $chat && (!$chat->project_id || in_array($chat->project_id, self::ids($actor), true));
    }

    public static function chat(array $actor, string $id): void
    {
        if (!self::canChat($actor, $id)) throw new InvalidArgumentException('Chat not found or access denied.');
    }

    public static function snapshot(array $state, array $actor): array
    {
        $ids = self::ids($actor);
        $state['projects'] = array_values(array_filter($state['projects'], fn($project) => in_array($project['id'], $ids, true)));
        $state['chats'] = array_values(array_filter($state['chats'], fn($chat) => !$chat['project_id'] || in_array($chat['project_id'], $ids, true)));
        $chatIds = array_column($state['chats'], 'id');
        // Global events can contain private project names; only admins receive them.
        $state['events'] = array_values(array_filter($state['events'], fn($event) => $event['chat_id'] ? in_array($event['chat_id'], $chatIds, true) : (bool)$actor['admin']));
        return $state;
    }

    public static function sharing(array $actor, array $data, bool $save): array
    {
        if (!$actor['admin']) throw new InvalidArgumentException('Administrator access required.');
        $project = Store::connection()->table('projects')->into(Project::class)->find(Input::text($data['project_id'] ?? null, 64)) ?? throw new InvalidArgumentException('Project not found.');
        if ($project->parent_id) throw new InvalidArgumentException('Manage sharing on the original project.');
        if ($save) {
            $visibility = $data['visibility'] ?? null;
            $members = $data['members'] ?? null;
            if (!in_array($visibility, ['private', 'public'], true) || !is_array($members) || !array_is_list($members) || count($members) > 1000) throw new InvalidArgumentException('Invalid sharing settings.');
            foreach ($members as $id) Input::text($id, 64);
            $members = array_values(array_unique($members));
            if (Store::connection()->table('accounts')->where('enabled', 1)->whereIn('user_id', $members)->count() !== count($members)) throw new InvalidArgumentException('Select enabled users only.');
            \app\service\Store::transaction(function () use ($project, $visibility, $members) {
                Store::connection()->table('projects')->into(Project::class)->where('id', $project->id)->update(['visibility'=>$visibility]);
                Store::connection()->table('project_members')->where('project_id', $project->id)->delete();
                if ($visibility === 'private' && $members !== []) {
                    Store::connection()->table('project_members')->insertMany(array_map(fn(string $userId): array => ['project_id' => $project->id, 'user_id' => $userId], $members));
                }
                Store::notify();
            });
        }
        $visibility = Store::connection()->table('projects')->where('id', $project->id)->value('visibility');
        return ['visibility'=>$visibility, 'members'=>Store::connection()->table('project_members')->where('project_id', $project->id)->pluck('user_id')];
    }
}
