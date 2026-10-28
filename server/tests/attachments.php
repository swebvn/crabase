<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Store as S, Actions as A, Attachments as U, Artifacts};
$root = sys_get_temp_dir().'/crabase-uploads-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
putenv('CRABASE_DB='.$root.'/test.sqlite');
putenv('CRABASE_WORKSPACE_ROOT='.$root);
function attachmentCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Attachment check failed.'); }
function rejected(callable $fn): void { try { $fn(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Invalid attachment accepted.'); }
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx','migrate','-c',dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    attachmentCheck(is_resource($process) && proc_close($process) === 0);
    S::db();
    S::run('INSERT INTO users VALUES (?,?,?,?)', ['uploader','Uploader','',gmdate('c')]);
    S::run('INSERT INTO users VALUES (?,?,?,?)', ['other','Other','',gmdate('c')]);
    $start = fn($name, $size) => A::handle('uploadStart', ['user_id'=>'uploader','name'=>$name,'size'=>$size]);
    rejected(fn() => $start('../bad', 1));
    rejected(fn() => $start('bad\\name', 1));
    rejected(fn() => $start('too-big', U::MAX_SIZE+1));
    rejected(fn() => $start('empty', 0));
    $id = $start('notes.txt', 5)['id'];
    $chunk = fn($offset, $text) => A::handle('uploadChunk', ['user_id'=>'uploader','id'=>$id,'offset'=>$offset,'bytes'=>base64_encode($text)]);
    rejected(fn() => U::pending([$id], 'other'));
    rejected(fn() => U::pending([$id], 'uploader'));
    rejected(fn() => $chunk(1, 'bad'));
    attachmentCheck($chunk(0, 'he')['received'] === 2);
    rejected(fn() => $chunk(0, 'he'));
    rejected(fn() => $chunk(2, 'overflow'));
    attachmentCheck($chunk(2, 'llo')['mime'] === 'text/plain');
    rejected(fn() => U::pending([$id,$id], 'uploader'));
    rejected(fn() => U::pending(array_fill(0,11,$id), 'uploader'));
    $chat = A::handle('create', ['title'=>'Uploads'])['id'];
    $send = ['chat_id'=>$chat,'user_id'=>'uploader','body'=>'','mode'=>'note','attachments'=>[$id]];
    rejected(fn() => A::handle('message', array_replace($send, ['user_id'=>'other'])));
    rejected(fn() => A::handle('message', array_replace($send, ['mode'=>'invalid'])));
    attachmentCheck(count(U::pending([$id], 'uploader')) === 1);
    S::connection()->execute("CREATE TRIGGER fail_attachment_message BEFORE INSERT ON messages BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $failed = false;
    try { A::handle('message', $send); } catch (\Throwable) { $failed = true; }
    attachmentCheck($failed && count(U::pending([$id], 'uploader')) === 1 && Artifacts::listing($chat) === []);
    S::connection()->execute('DROP TRIGGER fail_attachment_message');
    A::handle('message', $send);
    $message = S::thread($chat)['messages'][0];
    attachmentCheck($message['body'] === '' && $message['attachments'][0]['name'] === 'notes.txt');
    $file = $message['attachments'][0];
    attachmentCheck(file_get_contents(Artifacts::resolve($chat, $file['stored'])['path']) === 'hello');
    attachmentCheck(count(Artifacts::listing($chat)) === 1);
    rejected(fn() => A::handle('message', $send));
    $input = U::input(['chat_id'=>$chat,'message_id'=>$message['id']]);
    attachmentCheck(str_contains($input[0]['text'], 'notes.txt'));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=');
    $image = $start('screenshot.png', strlen($png))['id'];
    A::handle('uploadChunk', ['user_id'=>'uploader','id'=>$image,'offset'=>0,'bytes'=>base64_encode($png)]);
    A::handle('message', array_replace($send, ['attachments'=>[$image]]));
    $second = S::thread($chat)['messages'][1];
    attachmentCheck(U::input(['chat_id'=>$chat,'message_id'=>$second['id']])[1]['type'] === 'localImage');
    $avatar = $start('avatar.png', strlen($png))['id'];
    A::handle('uploadChunk', ['user_id'=>'uploader','id'=>$avatar,'offset'=>0,'bytes'=>base64_encode($png)]);
    rejected(fn () => A::handle('avatarSave', ['user_id'=>'other','id'=>$avatar]));
    $result = A::handle('avatarSave', ['user_id'=>'uploader','id'=>$avatar]);
    attachmentCheck(\app\model\User::query()->find('uploader')->avatar_url === $result['avatar_url']);
    attachmentCheck(file_get_contents(\app\service\Avatars::resolve(basename($result['avatar_url']))) === $png);
    attachmentCheck(\app\service\Avatars::resolve('../test.sqlite') === null);
    rejected(fn () => A::handle('avatarSave', ['user_id'=>'uploader','id'=>$avatar]));
    $text = $start('not-image.txt', 5)['id'];
    A::handle('uploadChunk', ['user_id'=>'uploader','id'=>$text,'offset'=>0,'bytes'=>base64_encode('hello')]);
    rejected(fn () => A::handle('avatarSave', ['user_id'=>'uploader','id'=>$text]));
    A::handle('uploadRemove', ['user_id'=>'uploader','id'=>$text]);
    $expired = $start('expired.txt', 1)['id'];
    S::run('UPDATE uploads SET expires=0 WHERE id=?', [$expired], false);
    U::cleanup();
    attachmentCheck(S::all('SELECT * FROM uploads') === []);
    $removed = $start('remove.txt', 1)['id'];
    rejected(fn() => A::handle('uploadRemove', ['id'=>$removed,'user_id'=>'other']));
    A::handle('uploadRemove', ['id'=>$removed,'user_id'=>'uploader']);
    echo "Attachment checks passed\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
