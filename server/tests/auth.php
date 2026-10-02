<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Auth as A, Store as S};
$db = tempnam(sys_get_temp_dir(), 'crabase-auth-');
putenv('CRABASE_DB='.$db);
function authCheck(bool $value): void { if (!$value) throw new RuntimeException('Auth check failed.'); }
function denied(callable $action): void {
    try { $action(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Expected rejection.');
}
try {
    putenv('CRABASE_ALLOWED_HOSTS=192.168.1.0/24,100.64.0.0/10');
    foreach (['localhost', '127.0.0.1', '192.168.1.152', '100.64.0.1', '100.127.255.254'] as $host) {
        authCheck(A::allowedHost($host) && A::allowedOrigin('http://'.$host.':8787'));
    }
    foreach (['evil.example', '192.168.2.1', '100.63.255.255', '100.128.0.0', '8.8.8.8'] as $host) {
        authCheck(!A::allowedHost($host) && !A::allowedOrigin('http://'.$host.':8787'));
    }
    foreach ([null, 'null', 'http://localhost:8787/path', 'http://localhost:9999', 'http://localhost.evil:8787', 'http://evil@localhost:8787'] as $origin) authCheck(!A::allowedOrigin($origin));
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx','migrate','-c',dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    authCheck(proc_close($process) === 0);
    $id = A::create(['name'=>'Admin','email'=>'admin@example.com','password'=>'test-password'],true)['id'];
    denied(fn () => A::create(['name'=>'Other','email'=>'other@example.com','password'=>'test-password'],true));
    denied(fn () => A::login(['email'=>'admin@example.com','password'=>'wrong'], 'test'));
    $token = A::login(['email'=>'ADMIN@example.com','password'=>'test-password'], 'test');
    $admin = A::user($token);
    authCheck($admin['avatar_required'] === true);
    authCheck($admin['id'] === $id && !isset($admin['password_hash']));
    authCheck(A::user('bad') === null && A::user(str_repeat('0',64)) === null);
    authCheck(str_starts_with(S::snapshot()['users'][0]['avatar_url'], 'https://www.gravatar.com/avatar/'));
    $memberId = A::create(['name'=>'Member','email'=>'member@example.com','password'=>'test-password'])['id'];
    $memberToken = A::login(['email'=>'member@example.com','password'=>'test-password'], 'test');
    $member = A::user($memberToken);
    denied(fn () => A::users($member));
    denied(fn () => A::update($member, ['id'=>$id],true));
    denied(fn () => A::update($admin, ['id'=>$id,'name'=>'Admin','email'=>'admin@example.com','admin'=>false,'enabled'=>true],true));
    denied(fn () => A::update($member, ['name'=>'Member','email'=>'changed@example.com'],false));
    denied(fn () => A::update($member, ['name'=>'Member','email'=>'changed@example.com','current_password'=>'test-password'],false));
    denied(fn () => A::update($admin, ['id'=>$memberId,'name'=>'Member','email'=>'changed@example.com','enabled'=>true],true));
    A::update($member,['name'=>'Member','email'=>'member@example.com','admin'=>true,'git_name'=>'Git Name','git_email'=>'git@example.com'],false);
    authCheck(!A::user($memberToken)['admin']);
    S::run("INSERT INTO chats (id,title,created_at,updated_at) VALUES ('test','Test','now','now')");
    S::run("INSERT INTO messages (chat_id,role,author,body,created_at,user_id) VALUES ('test','user','Member','hi','now',?)",[$memberId]);
    authCheck(str_contains(A::coauthors('test'),'Co-authored-by: Git Name <git@example.com>'));
    $messageId = S::all("SELECT id FROM messages WHERE chat_id='test'")[0]['id'];
    $job = ['chat_id'=>'test','message_id'=>$messageId,'prompt'=>'same message'];
    $input = app\service\ParticipantContext::input($job);
    $context = json_decode(explode("\n\nCurrent message:\n", substr($input,strlen("Participant context (JSON):\n")),2)[0],true);
    authCheck($context['sender'] === ['user_id'=>$memberId,'name'=>'Member']);
    authCheck(!str_contains($input,'@example.com') && !str_contains($input,'avatar'));
    S::run("INSERT INTO messages (chat_id,role,author,body,created_at,user_id) VALUES ('test','user','Admin','same message','now',?)",[$id]);
    authCheck(app\service\ParticipantContext::input($job) !== 'same message');
    $later = app\service\ParticipantContext::input($job);
    authCheck(str_contains($later,'"sender":{"user_id":"'.$memberId.'"'));
    authCheck(str_contains(app\service\ParticipantContext::input(['chat_id'=>'test','prompt'=>'legacy']), '"sender":null'));
    denied(fn () => A::update($member,['name'=>'Member','email'=>'member@example.com','git_name'=>"bad\nname",'git_email'=>'git@example.com'],false));
    A::update($admin,['id'=>$memberId,'name'=>'Member','email'=>'member@example.com','enabled'=>false],true);
    authCheck(A::user($memberToken) === null);
    denied(fn () => A::login(['email'=>'member@example.com','password'=>'test-password'],'test'));
    A::logout($token); authCheck(A::user($token) === null);
    $expired = A::login(['email'=>'admin@example.com','password'=>'test-password'],'test');
    S::run('UPDATE auth_sessions SET expires=? WHERE token_hash=?',[time()-1,hash('sha256',$expired)],false);
    authCheck(A::user($expired) === null);
    $state = str_repeat('a',64); $browser = str_repeat('b',64);
    S::run('INSERT INTO oauth_flows VALUES (?,?,?,?)',[hash('sha256',$state),hash('sha256',$browser),'verifier',time()+60],false);
    denied(fn () => app\service\OAuth::consume($state,str_repeat('c',64)));
    authCheck(app\service\OAuth::consume($state,$browser) === 'verifier');
    denied(fn () => app\service\OAuth::consume($state,$browser));
    S::run('INSERT INTO oauth_flows VALUES (?,?,?,?)',[hash('sha256',$state),hash('sha256',$browser),'verifier',time()-1],false);
    denied(fn () => app\service\OAuth::consume($state,$browser));
    authCheck(app\service\OAuth::account(['id'=>'provider-1','email'=>'admin@example.com']) === $id);
    $passportProfile = ['id'=>'passport-new','name'=>'Admin','email'=>'new-passport@example.com','email_verified'=>false,'admin'=>true];
    $passportId = app\service\OAuth::account($passportProfile);
    $passportAccount = app\model\Account::query()->find($passportId);
    authCheck($passportAccount->admin === 0 && $passportAccount->enabled === 1);
    authCheck(app\model\User::query()->find($passportId)->name !== 'Admin');
    authCheck(app\service\OAuth::account($passportProfile) === $passportId);
    authCheck(A::user(A::session($passportId))['id'] === $passportId);
    $count = app\model\Account::query()->count();
    denied(fn () => app\service\OAuth::account(['id'=>'missing-email']));
    denied(fn () => app\service\OAuth::account(['id'=>'invalid-email','email'=>'invalid']));
    authCheck(app\model\Account::query()->count() === $count);
    app\model\Account::query()->whereKey($passportId)->update(['enabled'=>0]);
    denied(fn () => app\service\OAuth::account($passportProfile));
    denied(fn () => app\service\OAuth::account(array_replace($passportProfile, ['id'=>'different-subject'])));
    authCheck(app\service\OAuth::account(['id'=>'provider-1','email'=>'admin@example.com','email_verified_at'=>'2026-09-09T00:00:00Z']) === $id);
    authCheck(app\service\OAuth::account(['id'=>'provider-1','email'=>'changed@example.com']) === $id);
    denied(fn () => app\service\OAuth::account(['id'=>'provider-2','email'=>'admin@example.com','email_verified'=>true]));
    for ($i=0;$i<10;$i++) denied(fn () => A::login(['email'=>'none@example.com','password'=>'wrong'],'limited'));
    denied(fn () => A::login(['email'=>'admin@example.com','password'=>'test-password'],'limited'));
    authCheck(!array_key_exists('password_hash', app\model\Account::query()->find($id)->toArray()));
    authCheck(!array_key_exists('token_hash', app\model\AuthSession::query()->first()->toArray()));
    echo "PASS: accounts, hashing, sessions, revocation, throttling, roles, last admin, Gravatar and coauthors.\n";
} finally {
    foreach ([$db,$db.'-wal',$db.'-shm'] as $file) if (is_file($file)) unlink($file);
}
