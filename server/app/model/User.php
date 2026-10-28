<?php

namespace app\model;

final class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'name', 'avatar_url', 'created_at'];

    public function pinnedProjects(): ModelCollection
    {
        return Project::query()->join('project_pins', 'project_pins.project_id', '=', 'projects.id')->where('project_pins.user_id', $this->id)->get();
    }

    public function messages(): ModelQuery
    {
        return Message::query()->where('user_id', $this->id);
    }
}
