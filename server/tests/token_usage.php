<?php
// Per-user token usage: credited to the person driving the turn (including steering and sub-agents),
// deduplicated per model call, and sent to a fake Console Event Relay with retries.
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Actions, Auth, Store, TokenUsage};

$directory = sys_get_temp_dir().'/crabase-usage-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$port = 18000 + random_int(0, 999);
file_put_contents("$directory/router.php", <<<'PHP'
<?php
$dir = __DIR__;
file_put_contents("$dir/requests.jsonl", json_encode(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'body' => json_decode(file_get_contents('php://input'), true)])."\n", FILE_APPEND);
$status = (int) (trim(@file_get_contents("$dir/status")) ?: 202);
http_response_code($status);
header('Content-Type: application/json');
echo '{"id":1}';
PHP);
$relay = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$directory/router.php"], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
putenv('CRABASE_DB='.$directory.'/test.sqlite');
putenv('CRABASE_WORKSPACE_ROOT='.$directory);
putenv('CODEX_BIN='.__DIR__.'/fake_codex.php');
putenv('APP_URL=https://crabase.test');
putenv("CRABASE_USAGE_RELAY_URL=http://127.0.0.1:$port/event-relay/crabase/events");
putenv('CRABASE_USAGE_RELAY_TOKEN=');

function usageCheck(bool $ok, string $what): void { if (!$ok) throw new RuntimeException('Token usage check failed: '.$what); }
$worker = new app\process\Codex();
$tick = new ReflectionMethod($worker, 'tick');
$rpc = new ReflectionMethod($worker, 'rpc');
$jobs = new ReflectionProperty($worker, 'jobs');
function pump(callable $condition): void {
    global $worker, $tick;
    $deadline = microtime(true) + 4;
    do { $tick->invoke($worker); if ($condition()) return; usleep(10000); } while (microtime(true) < $deadline);
    throw new RuntimeException('Token usage scheduler timed out');
}
$rows = fn () => Store::connection()->table('token_usages')->orderBy('id')->select('usage_id', 'email', 'total_tokens', 'sent_at', 'attempts', 'next_attempt_at', 'last_error')->get();
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/cua', 'migrate'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $migrate);
    usageCheck(is_resource($process) && proc_close($process) === 0, 'migrate');
    Store::db();
    $alice = Auth::create(['name'=>'Alice','email'=>'Alice@Example.com','password'=>'alice-password'])['id'];
    $bob = Auth::create(['name'=>'Bob','email'=>'bob@example.com','password'=>'bob-password'])['id'];
    // A fixture project gives the job a working folder without booting the full app (standalone chats read config()).
    Store::connection()->table('projects')->insert(['id'=>'fixture','name'=>'Fixture','path'=>$directory]);
    $chat = Actions::handle('create', ['title'=>'Usage','project_id'=>'fixture'])['id'];
    Actions::handle('message', ['chat_id'=>$chat,'body'=>'Alice asks','mode'=>'agent','user_id'=>$alice]);
    $job = fn () => array_values($jobs->getValue($worker))[0] ?? null;
    pump(fn () => !empty($job()['turn_id']));
    $usage = function (int $last, int $total, ?string $thread = null) use ($worker, $rpc, $job) {
        $rpc->invoke($worker, 'test/usage', ['threadId'=>$thread ?? $job()['thread_id'], 'turnId'=>$job()['turn_id'], 'last'=>$last, 'total'=>$total], fn ($result) => null);
    };

    // A new chat counts from zero; only growth of the running total is credited, so repeats add nothing,
    // including Codex replaying its previous report under another turn (as it does when a thread resumes).
    $usage(1200, 1200); $usage(1200, 1200); $usage(0, 1200);
    $rpc->invoke($worker, 'test/usage', ['threadId'=>$job()['thread_id'], 'turnId'=>'replayed-turn', 'last'=>1200, 'total'=>1200], fn ($result) => null);
    pump(fn () => count($rows()) === 1);
    usleep(100000); $tick->invoke($worker);
    usageCheck(count($rows()) === 1 && $rows()[0]['email'] === 'alice@example.com' && (int) $rows()[0]['total_tokens'] === 1200, 'dedupe and email');
    usageCheck(str_starts_with($rows()[0]['usage_id'], 'crabase_') && strlen($rows()[0]['usage_id']) === 48, 'usage id');

    // A sub-agent thread of the same job is credited to the same person.
    $state = $jobs->getValue($worker); $id = array_key_first($state);
    $state[$id]['activity']['agents']['child-thread'] = ['id'=>'child-thread','status'=>'running'];
    $jobs->setValue($worker, $state);
    $usage(500, 500, 'child-thread');
    pump(fn () => count($rows()) === 2);
    usageCheck($rows()[1]['email'] === 'alice@example.com' && (int) $rows()[1]['total_tokens'] === 500, 'sub-agent credit');

    // Bob steers the running turn: tokens from then on are his.
    Actions::handle('message', ['chat_id'=>$chat,'body'=>'Bob steers','mode'=>'agent','user_id'=>$bob]);
    pump(fn () => Store::connection()->table('jobs')->where('chat_id', $chat)->where('status', 'done')->count() === 1);
    $usage(1800, 3000);
    pump(fn () => count($rows()) === 3);
    usageCheck($rows()[2]['email'] === 'bob@example.com' && (int) $rows()[2]['total_tokens'] === 1800, 'steering credit');

    // Sending: AG's contract, no Authorization header without a token, retries keep the same ID.
    usageCheck(TokenUsage::hasDue(), 'due rows');
    usageCheck(TokenUsage::publishDue() === ['sent'=>3, 'retry'=>0, 'failed'=>0], 'publish sent');
    $sent = array_map(fn ($line) => json_decode($line, true), file("$directory/requests.jsonl", FILE_IGNORE_NEW_LINES));
    usageCheck(count($sent) === 3 && $sent[0]['auth'] === null, 'request count and no auth header');
    $first = $sent[0]['body'];
    usageCheck($first['event'] === 'ai.tokens.used' && $first['id'] === $rows()[0]['usage_id'] && array_keys($first['payload']) === ['email','total_tokens','occurred_at']
        && $first['payload']['email'] === 'alice@example.com' && $first['payload']['total_tokens'] === 1200
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['payload']['occurred_at']) === 1, 'payload contract');
    usageCheck(!TokenUsage::hasDue() && count(array_filter($rows(), fn ($r) => $r['sent_at'] !== null)) === 3, 'marked sent');

    putenv('CRABASE_USAGE_RELAY_TOKEN=relay-secret');
    $usage(700, 3700); pump(fn () => count($rows()) === 4);
    file_put_contents("$directory/status", '503');
    usageCheck(TokenUsage::publishDue() === ['sent'=>0, 'retry'=>1, 'failed'=>0], 'retry on 5xx');
    usageCheck($rows()[3]['sent_at'] === null && (int) $rows()[3]['attempts'] === 1 && $rows()[3]['last_error'] === 'HTTP 503' && !TokenUsage::hasDue(), 'backoff scheduled');
    Store::connection()->table('token_usages')->where('usage_id', $rows()[3]['usage_id'])->update(['next_attempt_at'=>gmdate('Y-m-d\TH:i:s\Z', time() - 1)]);
    file_put_contents("$directory/status", '202');
    usageCheck(TokenUsage::publishDue() === ['sent'=>1, 'retry'=>0, 'failed'=>0], 'retry succeeds');
    $sent = array_map(fn ($line) => json_decode($line, true), file("$directory/requests.jsonl", FILE_IGNORE_NEW_LINES));
    usageCheck($sent[3]['body'] === $sent[4]['body'] && $sent[4]['auth'] === 'Bearer relay-secret', 'retry keeps ID/payload and sends token');

    $usage(900, 4600); pump(fn () => count($rows()) === 5);
    file_put_contents("$directory/status", '409');
    usageCheck(TokenUsage::publishDue() === ['sent'=>0, 'retry'=>0, 'failed'=>1], 'conflict is not retried');
    usageCheck(!TokenUsage::hasDue() && $rows()[4]['last_error'] === 'HTTP 409', 'conflict parked');

    // A chat with history before this feature (resumed thread) only sets its baseline on the first report.
    Store::connection()->table('jobs')->update(['status'=>'done']);
    $history = Actions::handle('create', ['title'=>'History','project_id'=>'fixture'])['id'];
    Store::connection()->table('chats')->where('id', $history)->update(['thread_id'=>'old-thread']);
    Actions::handle('message', ['chat_id'=>$history,'body'=>'Resume','mode'=>'agent','user_id'=>$alice]);
    $resumed = function () use ($jobs, $worker, $history) { foreach ($jobs->getValue($worker) as $j) if ($j['chat_id'] === $history) return $j; return null; };
    pump(fn () => !empty($resumed()['turn_id']));
    $resumedUsage = fn (int $last, int $total, int $cached) => $rpc->invoke($worker, 'test/usage', ['threadId'=>'old-thread', 'turnId'=>$resumed()['turn_id'], 'last'=>$last, 'total'=>$total, 'cached'=>$cached], fn ($result) => null);
    $resumedUsage(112818, 5000000, 4800000); usleep(100000); $tick->invoke($worker); usleep(100000); $tick->invoke($worker);
    usageCheck(count($rows()) === 5, 'resumed history is not credited');
    // Only fresh tokens count: 119,638 more tokens of which 116,226 are cached re-reads credit 3,412.
    $resumedUsage(119638, 5119638, 4916226);
    pump(fn () => count($rows()) === 6);
    usageCheck((int) $rows()[5]['total_tokens'] === 3412 && $rows()[5]['email'] === 'alice@example.com', 'resumed growth credited without cached input');
    $resumedUsage(119638, 5119638, 4916226); usleep(100000); $tick->invoke($worker); usleep(100000); $tick->invoke($worker);
    usageCheck(count($rows()) === 6, 'replay of a cached-heavy report adds nothing');
    $rows5 = count($rows());

    // Disabled without a relay URL: nothing is recorded.
    putenv('CRABASE_USAGE_RELAY_URL=');
    $usage(100, 4700); usleep(100000); $tick->invoke($worker); usleep(100000); $tick->invoke($worker);
    usageCheck(count($rows()) === $rows5 && !TokenUsage::enabled(), 'disabled without URL');
    echo "PASS: token usage per requester (steering, sub-agents), fresh-token growth only (cached input excluded, replays, resumed history), AG-compatible payload, retries, conflicts and opt-in.\n";
} finally {
    if (is_resource($relay)) { proc_terminate($relay); proc_close($relay); }
    (new ReflectionMethod($worker, 'shutdown'))->invoke($worker);
    foreach (['router.php','requests.jsonl','status','test.sqlite','test.sqlite-wal','test.sqlite-shm'] as $file) @unlink("$directory/$file");
    exec('rm -rf '.escapeshellarg($directory));
}
