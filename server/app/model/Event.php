<?php

namespace app\model;

final class Event extends Model
{
    protected $table = 'events';
    public $timestamps = false;
    protected $fillable = ['chat_id', 'label', 'created_at'];

    public function chat(): ?Chat
    {
        return $this->chat_id ? Chat::find($this->chat_id) : null;
    }
}
