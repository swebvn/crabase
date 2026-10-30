<?php
namespace app\service;

use InvalidArgumentException;

final class Worktrees
{
    /** @param array<string, mixed> $project */
    public static function remove(array $project, bool $force = false): bool
    {
        if (!$project['parent_id']) throw new InvalidArgumentException('Only managed worktree folders can be deleted.');
        $path = ProjectWorkspace::workspacePath($project);
        $parent = Store::connection()->table('projects')->where('id', $project['parent_id'])->first() ?? throw new InvalidArgumentException('Original project not found.');
        $root = ProjectWorkspace::workspacePath($parent);
        [$status, $registered] = ProjectWorkspace::command($root, ['worktree', 'list', '--porcelain', '-z']);
        if ($status !== 0 || !in_array('worktree '.$path, explode("\0", $registered), true)) throw new InvalidArgumentException('This folder is not a registered worktree of the original project.');
        [$status, $changes] = ProjectWorkspace::command($path, ['status', '--porcelain', '--untracked-files=all']);
        if ($status !== 0) throw new InvalidArgumentException('Could not check worktree changes.');
        if (!$force && trim($changes) !== '') return false;
        // A single --force discards local changes but still refuses locked worktrees.
        [$status, , $error] = ProjectWorkspace::command($root, array_merge(['worktree', 'remove'], $force ? ['--force'] : [], ['--', $path]));
        if ($status !== 0) throw new InvalidArgumentException('Could not remove worktree: '.substr(trim($error), 0, 500));
        return true;
    }

    public static function projectFolder(string $name): string
    {
        return trim(substr(preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name)), 0, 80), '.-') ?: 'project';
    }

    private static function reserveFolder(string $directory): string
    {
        $adjectives = explode(' ', 'wobbly sleepy bouncy fuzzy silly happy tiny cozy dancing jolly curious mellow zippy sunny dizzy sneaky spicy bubbly fluffy lucky fancy giggly pudgy snappy');
        $animals = explode(' ', 'otter panda badger penguin capybara crab fox duck llama yak koala wombat gecko puffin quokka walrus moose sloth ferret hedgehog toucan turtle rabbit marmot');
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $path = $directory.'/'.$adjectives[random_int(0, count($adjectives) - 1)].'-'.$animals[random_int(0, count($animals) - 1)];
            // mkdir reserves the name atomically, including across projects with the same folder name.
            if (@mkdir($path, 0700)) return $path;
            if (!file_exists($path) && !is_link($path)) throw new \RuntimeException('Could not create worktree folder.');
        }
        throw new \RuntimeException('Could not find an unused worktree name. Try again.');
    }

    public static function create(array $data): array
    {
        $project = Store::connection()->table('projects')->where('id', Input::text($data['project_id'] ?? null, 64))->first()
            ?? throw new InvalidArgumentException('Project not found.');
        if ($project['parent_id'] || $project['archived']) throw new InvalidArgumentException('Create worktrees from an active original project.');
        $root = ProjectWorkspace::workspacePath($project);
        $branch = Input::text($data['branch'] ?? null, 160);
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $branch) ||
            ProjectWorkspace::command($root, ['check-ref-format', '--branch', $branch])[0] !== 0) {
            throw new InvalidArgumentException('Enter a valid new branch name, such as feature/login.');
        }
        [$status, $top] = ProjectWorkspace::command($root, ['rev-parse', '--show-toplevel']);
        if ($status !== 0 || realpath(trim($top)) !== $root) throw new InvalidArgumentException('The project must be a Git repository root.');
        if (ProjectWorkspace::command($root, ['rev-parse', '--verify', 'HEAD^{commit}'])[0] !== 0) throw new InvalidArgumentException('Commit the project before creating a worktree.');
        if (ProjectWorkspace::command($root, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch])[0] === 0) throw new InvalidArgumentException('That branch already exists. Choose a new branch name.');
        $id = bin2hex(random_bytes(8));
        $base = WorkspaceFolders::root().'/.worktrees';
        $projectDirectory = $base.'/'.self::projectFolder($project['name']);
        foreach ([$base, $projectDirectory] as $directory) {
            if (is_link($directory)) throw new InvalidArgumentException('Worktree folder cannot be a symlink.');
            if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) throw new \RuntimeException('Could not create worktree folder.');
        }
        $path = self::reserveFolder($projectDirectory);
        // Git checks branch collisions itself; never reset or reuse an existing branch/folder.
        // ponytail: local checkout blocks this worker for at most 10s; background provisioning if large repos need it.
        [$status, , $error] = ProjectWorkspace::command($root, ['-c', 'core.hooksPath=/dev/null', 'worktree', 'add', '-b', $branch, '--', $path, 'HEAD']);
        if ($status !== 0) throw new InvalidArgumentException('Could not create worktree: '.substr(trim($error), 0, 500));
        try {
            Store::connection()->table('projects')->insert(['id'=>$id, 'parent_id'=>$project['id'], 'name'=>$branch, 'path'=>$path]);
        } catch (\Throwable $error) {
            // Preserve the branch and folder on database failure; they can be recovered manually.
            throw new \RuntimeException('Worktree created at '.$path.' but could not be registered. Keep this path for recovery.', 0, $error);
        }
        return ['id'=>$id];
    }
}
