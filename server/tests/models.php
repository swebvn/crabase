<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use app\service\Store as S;
use app\service\Actions as A;
use app\service\ModelCatalog;
$path = tempnam(sys_get_temp_dir(), 'crabase-models-');
putenv('CRABASE_DB='.$path);
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Model selection check failed.'); }
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx', 'migrate', '-c', dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    check(is_resource($process) && proc_close($process) === 0);
    S::db();
    S::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', ['test', 'Test', dirname(__DIR__, 2)]);
    $project = S::all('SELECT id FROM projects LIMIT 1')[0]['id'];
    check(S::snapshot()['projects'][0]['created_order'] === 1);
    check(array_key_exists('branch', A::handle('projectContext', ['project_id'=>$project])));
    try { A::handle('projectContext', ['project_id'=>'missing']); throw new RuntimeException('Missing project accepted'); }
    catch (InvalidArgumentException) {}
    $users = array_column(\app\model\User::all()->toArray(), 'id', 'name');
    S::run('INSERT INTO users VALUES (?,?,?,?)', ['named-user','Name, with comma','',gmdate('c')]);
    $users['Name, with comma'] = 'named-user';
    $models = [['model'=>'test-model','defaultReasoningEffort'=>'low','supportedReasoningEfforts'=>[['reasoningEffort'=>'low'],['reasoningEffort'=>'high']]]];
    ModelCatalog::replace($models);
    $chat = A::handle('create', ['title'=>'Queue options'])['id'];
    foreach (['high','low'] as $effort) A::handle('message', ['chat_id'=>$chat,'body'=>'test','user_id'=>$users['user1'],'mode'=>'agent','model'=>'test-model','effort'=>$effort]);
    check(S::all('SELECT model,effort FROM jobs ORDER BY id') === [['model'=>'test-model','effort'=>'high'],['model'=>'test-model','effort'=>'low']]);
    check(A::agentOptions(['model'=>'test-model']) === ['model'=>'test-model','effort'=>'low']);
    foreach ([['model'=>'missing'],['model'=>'test-model','effort'=>'invalid'],['effort'=>'low']] as $bad) {
        try { A::handle('message',array_merge(['chat_id'=>$chat,'body'=>'invalid','mode'=>'agent'],$bad)); throw new RuntimeException('Invalid settings accepted'); }
        catch (InvalidArgumentException) {}
    }
    check(count(S::all('SELECT * FROM messages WHERE chat_id=?',[$chat])) === 2);
    $longTitle = str_repeat('x', 512);
    A::handle('rename', ['chat_id'=>$chat, 'title'=>$longTitle]);
    check(\app\model\Chat::find($chat)->title === $longTitle);
    foreach ([' ', str_repeat('x', 513)] as $invalidTitle) {
        try { A::handle('rename', ['chat_id'=>$chat, 'title'=>$invalidTitle]); throw new RuntimeException('Invalid chat title accepted'); }
        catch (InvalidArgumentException) {}
    }
    $participantsChat = A::handle('create', ['title'=>'Participant check'])['id'];
    A::handle('message', ['chat_id'=>$participantsChat,'body'=>'note','mode'=>'note','user_id'=>$users['user1']]);
    A::handle('message', ['chat_id'=>$participantsChat,'body'=>'another note','mode'=>'note','user_id'=>$users['user1']]);
    A::handle('message', ['chat_id'=>$participantsChat,'body'=>'note','mode'=>'note','user_id'=>$users['Name, with comma']]);
    S::run('INSERT INTO messages (chat_id,role,author,body,created_at) VALUES (?,?,?,?,?)', [$participantsChat,'assistant','Crab','hello',gmdate('c')]);
    $participants = array_column(S::snapshot()['chats'], 'participants', 'id')[$participantsChat];
    check($participants === ['user1','Name, with comma']);
    $user = \app\model\User::find($users['user1']);
    check($user->messages()->where('chat_id', $chat)->count() === 2);
    check(\app\model\Chat::find($chat)->jobs()->count() === 2);
    check(\app\model\Message::where('chat_id', $chat)->first()->user->id === $user->id);
    A::handle('userAvatar', ['user_id'=>$user->id,'avatar_url'=>'https://example.com/avatar.png']);
    check($user->fresh()->avatar_url === 'https://example.com/avatar.png');
    foreach ([['user_id'=>'missing','avatar_url'=>'https://example.com/a.png'], ['user_id'=>$user->id,'avatar_url'=>'javascript:alert(1)']] as $bad) {
        try { A::handle('userAvatar', $bad); throw new RuntimeException('Invalid profile accepted'); } catch (InvalidArgumentException) {}
    }
    try { A::handle('message', ['chat_id'=>$chat,'body'=>'spoof','user_id'=>'missing']); throw new RuntimeException('Unknown user accepted'); } catch (InvalidArgumentException) {}
    try {
        \support\Db::transaction(function () use ($user) {
            $user->update(['avatar_url'=>'https://example.com/rollback.png']);
            S::run('UPDATE users SET name=? WHERE id=?', ['rollback-name', $user->id]);
            throw new RuntimeException('rollback test');
        });
    } catch (RuntimeException) {}
    check($user->fresh()->name === 'user1' && $user->fresh()->avatar_url === 'https://example.com/avatar.png');
    $revision = fn () => (int)\app\model\Setting::query()->whereKey('revision')->value('value');
    $before = $revision();
    S::notify(\app\model\Message::query()->whereKey(-1)->update(['body'=>'missing']));
    check($revision() === $before);
    $message = \app\model\Message::query()->where('chat_id', $chat)->first();
    $original = $message->body;
    \app\model\Message::appendBody($message->id, "'quoted' \nΔ");
    check($message->fresh()->body === $original."'quoted' \nΔ" && $revision() === $before + 1);
    $before = $revision();
    try {
        \support\Db::transaction(function () use ($message) {
            \app\model\Message::appendBody($message->id, 'rolled back');
            \app\model\Setting::put('rollback-test', 'value');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {}
    check($revision() === $before && !\app\model\Setting::query()->whereKey('rollback-test')->exists());
    check(!str_ends_with($message->fresh()->body, 'rolled back'));
    $workspace = sys_get_temp_dir().'/crabase-project-'.bin2hex(random_bytes(8));
    $previousRoot = getenv('CRABASE_WORKSPACE_ROOT');
    mkdir($workspace); mkdir($workspace.'/Folder name');
    putenv('CRABASE_WORKSPACE_ROOT='.$workspace);
    try {
        $opened = A::handle('project', ['path'=>$workspace.'/Folder name', 'name'=>'Ignored custom name']);
        check(\app\model\Project::find($opened['id'])->name === 'Folder name');
        check(A::handle('project', ['path'=>$workspace.'/Folder name'])['id'] === $opened['id']);
        $id = $opened['id'];
        file_put_contents($workspace.'/Folder name/keep.txt', 'keep');
        $owned = A::handle('create', ['project_id'=>$id])['id'];
        A::handle('message', ['chat_id'=>$owned,'body'=>'keep until deleted','mode'=>'note','user_id'=>$users['user1']]);
        S::run('UPDATE chats SET thread_id=? WHERE id=?', ['runtime-thread', $owned]);
        A::handle('projectArchive', ['project_id'=>$id,'archived'=>true]);
        check(\app\model\Project::find($id)->archived === 1 && S::thread($owned)['chat']['archived'] === 0);
        A::handle('projectArchive', ['project_id'=>$id,'archived'=>false]);
        check(\app\model\Project::find($id)->archived === 0);
        try { A::handle('projectArchive', ['project_id'=>$id,'archived'=>'false']); throw new RuntimeException('Invalid archive accepted'); }
        catch (InvalidArgumentException) {}
        S::run('INSERT INTO jobs (chat_id,prompt,message_id) SELECT chat_id,body,id FROM messages WHERE chat_id=?', [$owned]);
        try { A::handle('projectDelete', ['project_id'=>$id]); throw new RuntimeException('Queued work deleted'); }
        catch (InvalidArgumentException) {}
        check(\app\model\Project::find($id) !== null);
        S::run("UPDATE jobs SET status='done' WHERE chat_id=?", [$owned]);
        S::run("UPDATE chats SET status='running' WHERE id=?", [$owned]);
        try { A::handle('projectDelete', ['project_id'=>$id]); throw new RuntimeException('Active chat deleted'); }
        catch (InvalidArgumentException) {}
        S::run("UPDATE chats SET status='idle' WHERE id=?", [$owned]);
        S::run('INSERT INTO approvals (chat_id,rpc_id,method,details) VALUES (?,?,?,?)', [$owned,'1','test','{}']);
        A::handle('projectDelete', ['project_id'=>$id]);
        check(\app\model\Project::find($id) === null && \app\model\Chat::find($owned) === null);
        foreach (['messages','jobs','approvals','events'] as $table) check(!S::all("SELECT * FROM $table WHERE chat_id=?", [$owned]));
        check(\app\model\Chat::find($participantsChat) !== null);
        check(file_get_contents($workspace.'/Folder name/keep.txt') === 'keep');
        check(S::all('PRAGMA foreign_key_check') === []);
        unlink($workspace.'/Folder name/keep.txt');
    } finally {
        rmdir($workspace.'/Folder name'); rmdir($workspace);
        putenv($previousRoot === false ? 'CRABASE_WORKSPACE_ROOT' : 'CRABASE_WORKSPACE_ROOT='.$previousRoot);
    }
    $models[0]['supportedReasoningEfforts'][] = ['reasoningEffort'=>'ultra'];
    $models[0]['defaultReasoningEffort'] = 'ultra';
    ModelCatalog::replace($models);
    check(A::agentOptions(['model'=>'test-model'])['effort'] === 'low');
    try { A::agentOptions(['model'=>'test-model','effort'=>'ultra']); throw new RuntimeException('Ultra accepted'); }
    catch (InvalidArgumentException) {}
    echo "PASS: model/effort validation, defaults, immutable queued selections, and rejection before saving.\n";
} finally {
    foreach ([$path,$path.'-wal',$path.'-shm'] as $file) if (is_file($file)) unlink($file);
}
