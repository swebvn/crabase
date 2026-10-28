<?php

namespace app\model;

final class Job extends Model
{
    protected $table = 'jobs';
    public $timestamps = false;
    protected $fillable = ['chat_id', 'prompt', 'model', 'effort', 'status', 'turn_id', 'cancel', 'message_id'];

    public static function nextQueued(int $limit): array
    {
        $db = \app\service\Store::connection();
        $active = $db->table('jobs as active')->selectRaw('1')
            ->whereColumn('active.chat_id', 'j.chat_id')->where('active.status', 'running');
        $earlier = $db->table('jobs as earlier')->selectRaw('1')
            ->whereColumn('earlier.chat_id', 'j.chat_id')->where('earlier.status', 'queued')
            ->where('earlier.cancel', 0)->whereColumn('earlier.id', '<', 'j.id');
        return $db->table('jobs as j')->select('j.*', 'c.thread_id', 'p.path')
            ->join('chats as c', 'c.id', '=', 'j.chat_id')->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->where('j.status', 'queued')->where('j.cancel', 0)->whereNotExists($active)->whereNotExists($earlier)
            ->orderBy('j.id')->limit($limit)->get();
    }

    public function chat(): ?Chat
    {
        return Chat::find($this->chat_id);
    }
}
