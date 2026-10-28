<?php

namespace app\model;

final class Chat extends Model
{
    protected $table = 'chats';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'project_id', 'title', 'status', 'archived', 'thread_id', 'created_at', 'updated_at'];

    public function project(): ?Project
    {
        return $this->project_id ? Project::find($this->project_id) : null;
    }

    public function messages(): ModelQuery
    {
        return Message::query()->where('chat_id', $this->id);
    }

    public function jobs(): ModelQuery
    {
        return Job::query()->where('chat_id', $this->id);
    }
}
