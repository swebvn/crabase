<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Actions, Auth, Store};
use Crab\Engine\EventLoop;
use Crab\Engine\Http\Request;
use Crab\Engine\Network\Connection;
use Crab\Engine\WebSocket\WebSocketSession;

$directory = sys_get_temp_dir().'/crabase-presence-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('CRABASE_DB='.$directory.'/test.sqlite');
putenv('CRABASE_WORKSPACE_ROOT='.$directory);
function presenceCheck(bool $ok, string $what): void { if (!$ok) throw new RuntimeException('Presence check failed: '.$what); }
// Uses Crab's real WebSocket session and records the frames it writes to a socket pair.
final class RecordingSession
{
    public array $sent = [];
    private string $input = '';
    private readonly Connection $connection;
    /** @var resource */
    private $client;

    public readonly WebSocketSession $session;

    public function __construct()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) throw new RuntimeException('Could not create a WebSocket test socket.');
        [$server, $client] = $pair;
        stream_set_blocking($client, false);
        $this->client = $client;
        $this->connection = new Connection(EventLoop::current(), $server, maxWriteBufferSize: 8 * 1024 * 1024);
        $this->session = new WebSocketSession($this->connection, static function (): void {});
    }

    public function request(string $token): Request
    {
        return new Request('GET', '/', '1.1', [
            'host' => ['127.0.0.1:8788'],
            'origin' => ['http://127.0.0.1:8787'],
            'cookie' => [Auth::COOKIE.'='.$token],
        ]);
    }

    public function drain(): void
    {
        while (($chunk = fread($this->client, 65536)) !== false && $chunk !== '') {
            $this->input .= $chunk;
        }
        while (strlen($this->input) >= 2) {
            $first = ord($this->input[0]);
            $second = ord($this->input[1]);
            $length = $second & 0x7f;
            $offset = 2;
            if ($length === 126) {
                if (strlen($this->input) < 4) return;
                $length = unpack('n', substr($this->input, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($this->input) < 10) return;
                $parts = unpack('N2', substr($this->input, 2, 8));
                $length = ($parts[1] * 4_294_967_296) + $parts[2];
                $offset = 10;
            }
            if (($second & 0x80) !== 0) $offset += 4;
            if (strlen($this->input) < $offset + $length) return;
            $payload = substr($this->input, $offset, $length);
            $this->input = substr($this->input, $offset + $length);
            if (($first & 0x0f) === 1) {
                $packet = json_decode($payload, true);
                if (is_array($packet)) $this->sent[] = $packet;
            }
        }
    }

    public function last(string $type): ?array
    {
        $this->drain();
        foreach (array_reverse($this->sent) as $packet) if (($packet['type'] ?? '') === $type) return $packet;
        return null;
    }

    public function clear(): void
    {
        $this->drain();
        $this->sent = [];
    }

    public function close(): void
    {
        $this->session->close();
        $this->connection->close();
        fclose($this->client);
    }
}
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx', 'migrate', '-c', dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    presenceCheck(is_resource($process) && proc_close($process) === 0, 'migrate');
    Store::db();
    $worker = new app\process\Codex();
    $connect = function (string $name) use ($worker): RecordingSession {
        $user = Auth::create(['name'=>$name, 'email'=>$name.'@example.com', 'password'=>bin2hex(random_bytes(16))]);
        $connection = new RecordingSession();
        $worker->onWebSocketConnect($connection->session, $connection->request(Auth::session($user['id'])));
        return $connection;
    };
    $call = function (RecordingSession $connection, string $action, array $data = []) use ($worker): array {
        static $sequence = 0;
        $connection->clear();
        $worker->onMessage($connection->session, json_encode(['id'=>++$sequence, 'action'=>$action, 'data'=>(object)$data]));
        $connection->drain();
        foreach ($connection->sent as $packet) if (($packet['id'] ?? null) === $sequence) return $packet;
        throw new RuntimeException('No reply to '.$action);
    };
    $ada = $connect('ada');
    $bob = $connect('bob');
    $chat = Actions::handle('create', ['title'=>'Shared'])['id'];
    $other = Actions::handle('create', ['title'=>'Other'])['id'];

    $call($ada, 'sync', ['chat_id'=>$chat]);
    presenceCheck($ada->last('presence') === ['type'=>'presence','chat_id'=>$chat,'viewers'=>['ada'],'typing'=>[]], 'first viewer');
    $ada->clear();
    $call($bob, 'sync', ['chat_id'=>$chat]);
    presenceCheck($ada->last('presence')['viewers'] === ['ada','bob'] && $bob->last('presence')['viewers'] === ['ada','bob'], 'second viewer reaches both');

    $ada->clear();
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$chat,'active'=>true])['result']), 'typing accepted');
    presenceCheck($ada->last('presence')['typing'] === ['bob'], 'typing reaches the other viewer');
    $ada->clear();
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>true]);
    presenceCheck($ada->last('presence') === null, 'refreshing typing does not rebroadcast');
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>false]);
    presenceCheck($ada->last('presence')['typing'] === [], 'typing stops');
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$other,'active'=>true])['error']), 'typing in an unopened chat is rejected');
    presenceCheck(isset($call($bob, 'typing', ['chat_id'=>$chat,'active'=>'yes'])['error']), 'typing requires a boolean');

    // Loading earlier history in the same chat must not rebroadcast presence or clear typing.
    $call($bob, 'typing', ['chat_id'=>$chat,'active'=>true]);
    $ada->clear();
    $call($bob, 'sync', ['chat_id'=>$chat,'before'=>1]);
    presenceCheck($ada->last('presence') === null, 'same-chat sync is silent');

    $call($bob, 'sync', ['chat_id'=>$other]);
    presenceCheck($ada->last('presence') === ['type'=>'presence','chat_id'=>$chat,'viewers'=>['ada'],'typing'=>[]], 'leaving clears viewer and typing');
    presenceCheck($bob->last('presence')['chat_id'] === $other && $bob->last('presence')['viewers'] === ['bob'], 'mover sees the new chat only');
    $call($bob, 'sync', ['chat_id'=>$chat]);
    $bob->clear();
    $worker->onClose($ada->session);
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
    if (isset($ada)) $ada->close();
    if (isset($bob)) $bob->close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($directory);
}
