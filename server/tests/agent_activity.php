<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\AgentActivity as Activity;
use app\service\Store;

function activityCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Agent activity check failed'); }
$path = tempnam(sys_get_temp_dir(), 'crabase-agents-');
putenv('CRABASE_DB='.$path);
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/cua', 'migrate'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    activityCheck(is_resource($process) && proc_close($process) === 0);
    Store::db();
    $chat = app\service\Actions::handle('create', ['title'=>'Activity test'])['id'];
    Store::run('INSERT INTO jobs (chat_id,prompt,status) VALUES (?,?,?)', [$chat,'Test','running']);
    $job = Store::all('SELECT * FROM jobs LIMIT 1')[0];
    $worker = new app\process\Codex();
    (new ReflectionProperty($worker,'jobs'))->setValue($worker, [(int)$job['id']=>$job+['thread_id'=>'parent','items'=>[], 'approvals'=>[], 'activity'=>[]]]);
    $receive = new ReflectionMethod($worker,'receive');
    $item = ['type'=>'collabAgentToolCall','id'=>'spawn-call','tool'=>'spawnAgent','status'=>'inProgress','senderThreadId'=>'parent','receiverThreadIds'=>[], 'agentsStates'=>[], 'prompt'=>'Review backend'];
    $send = function (array $item) use ($receive, $worker) {
        $receive->invoke($worker, ['method'=>'item/completed','params'=>['threadId'=>'parent','item'=>$item]]);
    };
    $send($item);
    $item['receiverThreadIds'] = ['child'];
    $item['agentsStates'] = ['child'=>['status'=>'running']];
    $item['status'] = 'completed';
    $send($item);
    $send(['type'=>'subAgentActivity','id'=>'activity','agentThreadId'=>'child','agentPath'=>'/root/review_backend','kind'=>'started']);
    $receive->invoke($worker, ['method'=>'item/agentMessage/delta','params'=>['threadId'=>'child','itemId'=>'message','delta'=>'Checking the queue']]);
    $receive->invoke($worker, ['method'=>'item/completed','params'=>['threadId'=>'child','item'=>['type'=>'commandExecution','id'=>'tool','command'=>'php -l test.php','aggregatedOutput'=>'No syntax errors']]]);
    $rows = Store::thread($chat)['messages'];
    activityCheck(count($rows) === 1 && $rows[0]['role'] === 'agent_activity');
    $state = json_decode($rows[0]['body'], true);
    activityCheck(array_keys($state['agents']) === ['child']);
    activityCheck($state['agents']['child']['name'] === 'review_backend');
    activityCheck($state['agents']['child']['message'] === 'Checking the queue');
    activityCheck(str_contains($state['agents']['child']['logs']['tool'], 'No syntax errors'));
    $receive->invoke($worker, ['method'=>'item/agentMessage/delta','params'=>['threadId'=>'unrelated','itemId'=>'other','delta'=>'Must not leak']]);
    activityCheck(Store::thread($chat)['messages'][0]['body'] === $rows[0]['body']);
    $item['tool'] = 'wait';
    $item['agentsStates']['child'] = ['status'=>'completed','message'=>'Queue checks passed'];
    $send($item);
    $item['tool'] = 'closeAgent'; $item['agentsStates']['child'] = ['status'=>'shutdown'];
    $send($item);
    (new ReflectionMethod($worker,'finish'))->invoke($worker, (int)$job['id'], 'completed');
    $state = json_decode(Store::thread($chat)['messages'][0]['body'], true);
    activityCheck(!$state['active'] && $state['agents']['child']['status'] === 'completed');
    activityCheck($state['agents']['child']['message'] === 'Queue checks passed');
    $unfinished = Activity::finish(['active'=>true,'agents'=>['x'=>['status'=>'running']]]);
    activityCheck($unfinished['agents']['x']['status'] === 'unknown');
    $failed = Activity::apply([], ['type'=>'collabAgentToolCall','id'=>'failed','tool'=>'spawnAgent','status'=>'failed','receiverThreadIds'=>[],'agentsStates'=>[]]);
    activityCheck($failed['agents']['spawn:failed']['status'] === 'errored');
    echo "PASS: subagent lifecycle, one persisted turn block, child updates/tool output, unrelated-thread isolation, completion and failure.\n";
} finally {
    foreach ([$path,$path.'-wal',$path.'-shm'] as $file) if (is_file($file)) unlink($file);
}
