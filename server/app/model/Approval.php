<?php

namespace app\model;

final class Approval extends Model
{
    protected $table = 'approvals';
    public $timestamps = false;
    protected $fillable = ['chat_id', 'rpc_id', 'method', 'details', 'decision'];

    public function chat(): ?Chat
    {
        return Chat::find($this->chat_id);
    }
}
