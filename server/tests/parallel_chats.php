<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\Store;
use app\service\Actions;

$directory = sys_get_temp_dir().'/crabase-parallel-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('CRABASE_DB='.$directory.'/test.sqlite');
putenv('CRABASE_WORKSPACE_ROOT='.$directory);
putenv('CRABASE_PARALLEL_CHATS=2');
putenv('CODEX_BIN='.__DIR__.'/fake_codex.php');
$worker = new app\process\Codex();
$tick = new ReflectionMethod($worker,'tick');
$rpc = new ReflectionMethod($worker,'rpc');
function parallelCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Parallel chat check failed'); }
function pump(callable $condition): void {
    global $worker, $tick;
    $deadline = microtime(true) + 4;
    do {
        $tick->invoke($worker);
        if ($condition()) return;
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Parallel scheduler timed out');
}
function job(int $id): array { return Store::all('SELECT * FROM jobs WHERE id=?', [$id])[0]; }
function complete(int $id): void {
    global $worker, $rpc;
    $job = job($id);
    $thread = Store::all('SELECT thread_id FROM chats WHERE id=?', [$job['chat_id']])[0]['thread_id'];
    $rpc->invoke($worker, 'test/complete', ['threadId'=>$thread,'turnId'=>$job['turn_id']], fn($result)=>null);
}
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/cua', 'migrate'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    parallelCheck(is_resource($process) && proc_close($process) === 0);
    Store::db();
    $chatA = Actions::handle('create', ['title'=>'A'])['id'];
    $chatB = Actions::handle('create', ['title'=>'B'])['id'];
    $chatC = Actions::handle('create', ['title'=>'C'])['id'];
    // Reuse a fixture directory instead of creating scratch folders in the real runtime.
    Store::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', ['fixture','Fixture',$directory]);
    Store::run('UPDATE chats SET project_id=?', ['fixture']);
    foreach ([[$chatA,'A first'],[$chatA,'A second'],[$chatB,'B first'],[$chatC,'C first']] as [$chat,$prompt]) {
        Store::run('INSERT INTO jobs (chat_id,prompt) VALUES (?,?)', [$chat,$prompt]);
    }
    pump(fn() => !empty(job(1)['turn_id']) && !empty(job(3)['turn_id']));
    parallelCheck(job(1)['status'] === 'running' && job(3)['status'] === 'running');
    parallelCheck(job(2)['status'] === 'queued' && job(4)['status'] === 'queued');
    parallelCheck(Store::thread($chatA)['messages'][0]['body'] === 'A first');
    parallelCheck(Store::thread($chatB)['messages'][0]['body'] === 'B first');
    complete(3);
    pump(fn() => !empty(job(4)['turn_id']));
    parallelCheck(job(1)['status'] === 'running' && job(2)['status'] === 'queued');
    Actions::handle('cancel', ['chat_id'=>$chatA]);
    pump(fn() => job(1)['status'] === 'interrupted' && job(2)['status'] === 'cancelled');
    parallelCheck(job(4)['status'] === 'running');
    // A request error is scoped to its job, not the entire persistent server.
    Store::run('INSERT INTO jobs (chat_id,prompt) VALUES (?,?)', [$chatB,'B second']);
    pump(fn() => !empty(job(5)['turn_id']));
    $rpc->invoke($worker, 'test/fail', [], fn($result)=>null, 5);
    pump(fn() => job(5)['status'] === 'failed');
    parallelCheck(job(4)['status'] === 'running');
    // Old turn completion must not finish a newer turn in the same thread.
    Store::run('INSERT INTO jobs (chat_id,prompt) VALUES (?,?)', [$chatB,'B third']);
    pump(fn() => !empty(job(6)['turn_id']));
    complete(3);
    for ($i=0;$i<5;$i++) { $tick->invoke($worker); usleep(10000); }
    parallelCheck(job(6)['status'] === 'running');
    $threadB = Store::all('SELECT thread_id FROM chats WHERE id=?', [$chatB])[0]['thread_id'];
    (new ReflectionMethod($worker,'receive'))->invoke($worker, ['id'=>900,'method'=>'item/commandExecution/requestApproval','params'=>['threadId'=>$threadB,'turnId'=>job(6)['turn_id'],'command'=>'test']]);
    parallelCheck(Store::thread($chatB)['chat']['status'] === 'approval');
    parallelCheck(Store::thread($chatC)['chat']['status'] === 'running');
    $approval = Store::thread($chatB)['approvals'][0]['id'];
    Actions::handle('approval', ['chat_id'=>$chatB,'approval_id'=>$approval,'decision'=>'accept']);
    pump(fn() => Store::thread($chatB)['chat']['status'] === 'running');
    Store::run('INSERT INTO jobs (chat_id,prompt) VALUES (?,?)', [$chatA,'A third']);
    Store::run('INSERT INTO jobs (chat_id,prompt) VALUES (?,?)', [$chatA,'A fourth']);
    complete(4);
    pump(fn() => !empty(job(7)['turn_id']));
    parallelCheck(job(8)['status'] === 'queued');
    complete(7);
    pump(fn() => !empty(job(8)['turn_id']));
    $rpc->invoke($worker, 'test/crash', [], fn($result)=>null);
    pump(fn() => job(8)['status'] === 'failed' && job(6)['status'] === 'failed');
    echo "PASS: simultaneous chats, concurrency limit, per-chat FIFO, stream isolation, cancellation, scoped RPC errors, stale events and shared-process failure.\n";
} finally {
    (new ReflectionMethod($worker,'shutdown'))->invoke($worker);
    foreach (glob($directory.'/.artifacts/*') ?: [] as $folder) rmdir($folder);
    if (is_dir($directory.'/.artifacts')) rmdir($directory.'/.artifacts');
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
