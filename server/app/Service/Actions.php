<?php

namespace App\Service;

use InvalidArgumentException;

/** WebSocket workspace actions: one place for authorization, routing and revision notifications. */
final class Actions
{
    /** Read-only actions that must not bump the workspace revision. */
    private const READS = ['projectContext', 'projectFolders', 'projectFolderCreate', 'projectWorkspace', 'projectFile', 'projectDiff'];

    /**
     * Entry point for a signed-in browser request. The worker has already checked project_id/chat_id access;
     * identity always comes from the session, never from the client payload.
     */
    public static function request(array $actor, string $action, array $data): array
    {
        $data['user_id'] = $actor['id'];
        return match ($action) {
            'profile' => ['user' => $actor],
            'profileSave' => Auth::update($actor, $data, false),
            'userUpdate' => Auth::update($actor, $data, true),
            'usersList' => ['users' => Auth::users($actor)] + ($actor['admin'] ? ['oauth' => OAuth::settings()] : []),
            'projectSharing', 'projectSharingSave' => ProjectAccess::sharing($actor, $data, $action === 'projectSharingSave'),
            // Any signed-in user may add projects and worktrees; the creator stays a member if an admin makes it private.
            'project' => Projects::create($data, $actor),
            'projectArchive', 'projectDelete' => $actor['admin']
                ? self::handle($action, $data)
                : throw new InvalidArgumentException('Administrator access required to manage projects.'),
            'userCreate', 'oauthSettingsSave', 'usageReport' => self::admin($actor, $action, $data),
            default => self::handle($action, $data),
        };
    }

    /** Trusted dispatch for already-authorized requests (and tests). */
    public static function handle(string $action, array $data): array
    {
        Store::db();
        if (in_array($action, ['uploadStart', 'uploadChunk', 'uploadRemove'], true)) return Attachments::handle($action, $data);
        $result = match ($action) {
            'projectFolders' => WorkspaceFolders::listing($data),
            'projectFolderCreate' => WorkspaceFolders::create($data),
            'projectContext' => ProjectWorkspace::context($data),
            'projectWorkspace' => ProjectWorkspace::snapshot($data),
            'projectFile' => ProjectWorkspace::file($data),
            'projectDiff' => ProjectWorkspace::diff($data),
            'projectSave' => ProjectWorkspace::save($data),
            'avatarSave' => Attachments::saveAvatar($data),
            'project' => Projects::create($data),
            'projectPin' => Projects::pin($data),
            'projectArchive', 'projectDelete' => Projects::manage($action, $data),
            'worktreeCreate' => Worktrees::create($data),
            'create' => Chats::create($data),
            'rename' => Chats::rename($data),
            'message' => Chats::send($data),
            'chatModel' => Chats::saveModel($data),
            'archive' => Chats::archive($data),
            'cancel' => Chats::cancel($data),
            'approval' => Chats::decideApproval($data),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
        if (!in_array($action, self::READS, true)) Store::notify();
        return $result;
    }

    private static function admin(array $actor, string $action, array $data): array
    {
        if (!$actor['admin']) throw new InvalidArgumentException('Administrator access required.');
        return match ($action) {
            'userCreate' => Auth::create($data),
            'oauthSettingsSave' => OAuth::saveSettings($data),
            'usageReport' => TokenUsage::report($data['days'] ?? 30),
        };
    }
}
