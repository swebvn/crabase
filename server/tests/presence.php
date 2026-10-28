<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Actions, Auth, Store};
use app\process\{LegacyConnection, LegacyRequest, LegacyWorker};

$directory = sys_get_temp_dir().'/crabase-presence-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('CRABASE_DB='.$directory.'/test.sqlite');
putenv('CRABASE_WORKSPACE_ROOT='.$directory);
function presenceCheck(bool $ok, string $what): void { if (!$ok) throw new RuntimeException('Presence check failed: '.$what); }
// Records packets instead of writing to a socket; the worker only needs id and send().
final class RecordingConnection extends LegacyConnection
{
    public array $sent = [];
    public function send(string $sendBuffer): void { $this->sent[] = json_decode($sendBuffer, true); }
    public function close(): void {}
    public function last(string $type): ?array
    {
        foreach (array_reverse($this->sent) as $packet) if (($packet['type'] ?? '') === $type) return $packet;
        return null;
    }
}
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx', 'migrate', '-c', dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    presenceCheck(is_resource($process) && proc_close($process) === 0, 'migrate');
    Store::db();
    $worker = new app\process\Codex();
    $server = new LegacyWorker();
    (new ReflectionProperty($worker, 'worker'))->setValue($worker, $server);
    $connect = function (int $id, string $name) use ($worker, $server): RecordingConnection {
        $user = Auth::create(['name'=>$name, 'email'=>$name.'@example.com', 'password'=>bin2hex(random_bytes(16))]);
        $connection = (new ReflectionClass(RecordingConnection::class))->newInstanceWithoutConstructor();
        $connection->id = $id;
        $connection->sent = [];
        $server->connections[$id] = $connection;
        $request = new \Crab\Http\Request('GET', 'http://127.0.0.1:8788/', ['Origin' => 'http://127.0.0.1:8787', 'Cookie' => Auth::COOKIE.'='.Auth::session($user['id'])]);
        $worker->onWebSocketConnect($connection, new LegacyRequest($request));
        return $connection;
    };
    $call = function (RecordingConnection $connection, string $action, array $data = []) use ($worker): array {
        static $sequence = 0;
        $connection->sent = [];
        $worker->onMessage($connection, json_encode(['id'=>++$sequence, 'action'=>$action, 'data'=>(object)$data]));
        foreach ($connection->sent as $packet) if (($packet['id'] ?? null) === $sequence) return $packet;
        throw new RuntimeException('No reply to '.$action);
    };
    $ada = $connect(1, 'ada');
    $bob = $connect(2, 'bob');
    $chat = Actions::handle('create', ['title'=>'Shared'])['id'];
    $other = Actions::handle('create', ['title'=>'Other'])['id'];

    $call($ada, 'sync', ['chat_id'=>$chat]);
    presenceCheck($ada->last('presence') === ['type'=>'presence','chat_id'=>$chat,'viewers'=>['ada'],'typing'=>[]], 'first viewer');
    $ada->sent = [];
    $call($bob, 'sync', ['chat_id'=>$chat]);
    presenceCheck($ada->last('presence')['viewers'] === ['ada','bob'] && $bob->last('presence')['viewers'] === ['ada','bob'], 'second viewer reaches both');

    $ada->sent = [];
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$chat,'active'=>true])['result']), 'typing accepted');
    presenceCheck($ada->last('presence')['typing'] === ['bob'], 'typing reaches the other viewer');
    $ada->sent = [];
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>true]);
    presenceCheck($ada->last('presence') === null, 'refreshing typing does not rebroadcast');
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>false]);
    presenceCheck($ada->last('presence')['typing'] === [], 'typing stops');
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$other,'active'=>true])['error']), 'typing in an unopened chat is rejected');
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$chat,'active'=>'yes'])['error']), 'typing requires a boolean');

    // Loading earlier history in the same chat must not rebroadcast presence or clear typing.
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>true]);
    $ada->sent = [];
    $call($bob, 'sync', ['chat_id'=>$chat,'before'=>1]);
    presenceCheck($ada->last('presence') === null, 'same-chat sync is silent');

    $call($bob, 'sync', ['chat_id'=>$other]);
    presenceCheck($ada->last('presence') === ['type'=>'presence','chat_id'=>$chat,'viewers'=>['ada'],'typing'=>[]], 'leaving clears viewer and typing');
    presenceCheck($bob->last('presence')['chat_id'] === $other && $bob->last('presence')['viewers'] === ['bob'], 'mover sees the new chat only');
    $call($bob, 'sync', ['chat_id'=>$chat]);
    $bob->sent = [];
    $worker->onClose($ada);
    presenceCheck($bob->last('presence')['viewers'] === ['bob'], 'disconnect removes the viewer');

    // The snapshot names whose request the agent is running, so others can be told their message steers it.
    $bobId = Store::all('SELECT id FROM users WHERE name=?', ['bob'])[0]['id'];
    Actions::handle('message', ['chat_id'=>$chat, 'body'=>'Fix it', 'mode'=>'note', 'user_id'=>$bobId]);
    $message = Store::all('SELECT id FROM messages WHERE chat_id=?', [$chat])[0]['id'];
    $running = fn () => array_column(Store::snapshot()['chats'], 'running_by', 'id')[$chat];
    presenceCheck($running() === null, 'idle chat has no requester');
    Store::run("INSERT INTO jobs (chat_id,prompt,status,message_id) VALUES (?,?,'running',?)", [$chat, 'Fix it', $message]);
    presenceCheck($running() === 'bob', 'running chat names the requester');
    echo "PASS: chat viewers, typing start/refresh/stop, validation, chat switches, disconnects and running-request owner.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($directory);
}
