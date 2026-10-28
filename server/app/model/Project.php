<?php

namespace app\model;

final class Project extends Model
{
    protected $table = 'projects';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'name', 'path', 'archived', 'parent_id', 'visibility'];
    protected $booleans = ['archived'];

    public function members(): ModelCollection
    {
        return User::query()->join('project_members', 'project_members.user_id', '=', 'users.id')->where('project_members.project_id', $this->id)->get();
    }

    public function workspacePath(): string
    {
        if (!$this->parent_id) return \app\service\WorkspaceFolders::resolve($this->path);
        $base = \app\service\WorkspaceFolders::root().'/.worktrees';
        $expected = $this->path;
        $parent = self::query()->find($this->parent_id);
        $directory = $parent ? $base.'/'.\app\service\Worktrees::projectFolder($parent->name) : '';
        $legacy = $base.'/'.$this->parent_id.'/'.$this->id;
        if ($expected !== $legacy && (!$parent || dirname($expected) !== $directory || !preg_match('/^[a-z]+-[a-z]+$/D', basename($expected)))) {
            throw new \InvalidArgumentException('Invalid worktree folder.');
        }
        foreach ([dirname(dirname($expected)), dirname($expected), $expected] as $path) {
            if (is_link($path)) throw new \InvalidArgumentException('Worktree folder cannot be a symlink.');
        }
        if ($this->path !== $expected || realpath($expected) !== $expected || !is_dir($expected) || !is_readable($expected)) {
            throw new \InvalidArgumentException('Worktree folder is unavailable.');
        }
        return $expected;
    }

    public function chats(): ModelQuery
    {
        return Chat::query()->where('project_id', $this->id);
    }
}
