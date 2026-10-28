<?php

namespace app\model;

final class Message extends Model
{
    protected $table = 'messages';
    public $timestamps = false;
    protected $fillable = ['chat_id', 'role', 'author', 'body', 'created_at', 'user_id', 'attachments'];
    protected $casts = ['attachments' => 'array'];

    public static function appendBody(int $id, string $delta): void
    {
        // Keep streaming atomic: a read/modify/save cycle can lose concurrent deltas.
        \app\service\Store::notify(\app\service\Store::connection()->execute('UPDATE messages SET body=body || ? WHERE id=?', [$delta, $id]));
    }

    public function user(): ?User
    {
        return $this->user_id ? User::find($this->user_id) : null;
    }

    public function chat(): ?Chat
    {
        return Chat::find($this->chat_id);
    }
}
