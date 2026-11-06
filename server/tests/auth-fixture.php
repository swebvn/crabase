<?php
// Disposable credentials for the live smoke test; never printed except to its parent process.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Service\{Auth, Store};
if (($argv[1] ?? '') === 'cleanup') {
    foreach (array_slice($argv,2) as $id) {
        Store::run('DELETE FROM auth_sessions WHERE user_id=?',[$id],false);
        Store::run('DELETE FROM accounts WHERE user_id=?',[$id]);
    }
    exit;
}
$result = [];
for ($i=0;$i<2;$i++) {
    $suffix = bin2hex(random_bytes(6));
    $name = 'smoke-'.$suffix;
    $email = $name.'@example.com';
    $password = bin2hex(random_bytes(16));
    $id = Auth::create(['name'=>$name,'email'=>$email,'password'=>$password,'admin'=>$i === 0])['id'];
    $result[] = compact('id','name','email','password');
}
echo json_encode($result);
