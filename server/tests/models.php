<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use app\service\Store as S;
use app\service\Actions as A;
use app\service\ModelCatalog;
use app\record\{Chat, User, Message, Project};
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
    $users = array_column(S::connection()->table('users')->get(), 'id', 'name');
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
    check(S::connection()->table('chats')->into(Chat::class)->find($chat)->title === $longTitle);
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
    $user = S::connection()->table('users')->into(User::class)->find($users['user1']);
    check(S::connection()->table('messages')->where('user_id', $user->id)->where('chat_id', $chat)->count() === 2);
    check(S::connection()->table('jobs')->where('chat_id', $chat)->count() === 2);
    check(S::connection()->table('messages')->where('chat_id', $chat)->where('user_id', $user->id)->exists());
    A::handle('userAvatar', ['user_id'=>$user->id,'avatar_url'=>'https://example.com/avatar.png']);
    check(S::connection()->table('users')->where('id', $user->id)->value('avatar_url') === 'https://example.com/avatar.png');
    foreach ([['user_id'=>'missing','avatar_url'=>'https://example.com/a.png'], ['user_id'=>$user->id,'avatar_url'=>'javascript:alert(1)']] as $bad) {
        try { A::handle('userAvatar', $bad); throw new RuntimeException('Invalid profile accepted'); } catch (InvalidArgumentException) {}
    }
    try { A::handle('message', ['chat_id'=>$chat,'body'=>'spoof','user_id'=>'missing']); throw new RuntimeException('Unknown user accepted'); } catch (InvalidArgumentException) {}
    try {
        S::transaction(function () use ($user) {
            S::connection()->table('users')->where('id', $user->id)->update(['avatar_url'=>'https://example.com/rollback.png']);
            S::run('UPDATE users SET name=? WHERE id=?', ['rollback-name', $user->id]);
            throw new RuntimeException('rollback test');
        });
    } catch (RuntimeException) {}
    check(S::connection()->table('users')->where('id', $user->id)->value('name') === 'user1' && S::connection()->table('users')->where('id', $user->id)->value('avatar_url') === 'https://example.com/avatar.png');
    $revision = fn () => (int)S::connection()->table('settings')->where('key', 'revision')->value('value');
    $before = $revision();
    S::notify(S::connection()->table('messages')->where('id', -1)->update(['body'=>'missing']));
    check($revision() === $before);
    $message = S::connection()->table('messages')->into(Message::class)->where('chat_id', $chat)->first();
    $original = $message->body;
    \app\service\MessageBody::append($message->id, "'quoted' \nΔ");
    check(S::connection()->table('messages')->where('id', $message->id)->value('body') === $original."'quoted' \nΔ" && $revision() === $before + 1);
    $before = $revision();
    try {
        S::transaction(function () use ($message) {
            \app\service\MessageBody::append($message->id, 'rolled back');
            S::notify(S::connection()->table('settings')->upsert([['key'=>'rollback-test', 'value'=>'value']], ['key'], ['value']));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {}
    check($revision() === $before && !S::connection()->table('settings')->where('key', 'rollback-test')->exists());
    check(!str_ends_with((string)S::connection()->table('messages')->where('id', $message->id)->value('body'), 'rolled back'));
    $workspace = sys_get_temp_dir().'/crabase-project-'.bin2hex(random_bytes(8));
    $previousRoot = getenv('CRABASE_WORKSPACE_ROOT');
    mkdir($workspace); mkdir($workspace.'/Folder name');
    putenv('CRABASE_WORKSPACE_ROOT='.$workspace);
    try {
        $opened = A::handle('project', ['path'=>$workspace.'/Folder name', 'name'=>'Ignored custom name']);
        check(S::connection()->table('projects')->into(Project::class)->find($opened['id'])->name === 'Folder name');
        check(A::handle('project', ['path'=>$workspace.'/Folder name'])['id'] === $opened['id']);
        $id = $opened['id'];
        file_put_contents($workspace.'/Folder name/keep.txt', 'keep');
        $owned = A::handle('create', ['project_id'=>$id])['id'];
        A::handle('message', ['chat_id'=>$owned,'body'=>'keep until deleted','mode'=>'note','user_id'=>$users['user1']]);
        S::run('UPDATE chats SET thread_id=? WHERE id=?', ['runtime-thread', $owned]);
        A::handle('projectArchive', ['project_id'=>$id,'archived'=>true]);
        check((int)S::connection()->table('projects')->where('id', $id)->value('archived') === 1 && S::thread($owned)['chat']['archived'] === 0);
        A::handle('projectArchive', ['project_id'=>$id,'archived'=>false]);
        check((int)S::connection()->table('projects')->where('id', $id)->value('archived') === 0);
        try { A::handle('projectArchive', ['project_id'=>$id,'archived'=>'false']); throw new RuntimeException('Invalid archive accepted'); }
        catch (InvalidArgumentException) {}
        S::run('INSERT INTO jobs (chat_id,prompt,message_id) SELECT chat_id,body,id FROM messages WHERE chat_id=?', [$owned]);
        try { A::handle('projectDelete', ['project_id'=>$id]); throw new RuntimeException('Queued work deleted'); }
        catch (InvalidArgumentException) {}
        check(S::connection()->table('projects')->where('id', $id)->exists());
        S::run("UPDATE jobs SET status='done' WHERE chat_id=?", [$owned]);
        S::run("UPDATE chats SET status='running' WHERE id=?", [$owned]);
        try { A::handle('projectDelete', ['project_id'=>$id]); throw new RuntimeException('Active chat deleted'); }
        catch (InvalidArgumentException) {}
        S::run("UPDATE chats SET status='idle' WHERE id=?", [$owned]);
        S::run('INSERT INTO approvals (chat_id,rpc_id,method,details) VALUES (?,?,?,?)', [$owned,'1','test','{}']);
        A::handle('projectDelete', ['project_id'=>$id]);
        check(!S::connection()->table('projects')->where('id', $id)->exists() && !S::connection()->table('chats')->where('id', $owned)->exists());
        foreach (['messages','jobs','approvals','events'] as $table) check(!S::all("SELECT * FROM $table WHERE chat_id=?", [$owned]));
        check(S::connection()->table('chats')->where('id', $participantsChat)->exists());
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
