<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Service\Artifacts as A;
$root = sys_get_temp_dir().'/crabase-artifacts-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
putenv('CRABASE_WORKSPACE_ROOT='.$root);
function artifactCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Artifact check failed'); }
$chat = '1234567890abcdef';
try {
    file_put_contents($root.'/report.csv', "name,value\nhello,123\n");
    $first = A::publish($chat, $root.'/report.csv');
    $second = A::publish($chat, $root.'/report.csv');
    artifactCheck(count(A::listing($chat)) === 2);
    artifactCheck(A::listing('0000000000000000') === []);
    artifactCheck(A::listing($chat)[0]['size'] === filesize($root.'/report.csv'));
    artifactCheck($first['url'] !== $second['url']);
    artifactCheck($first['url'] === '/files/'.$chat.'/'.$first['name']);
    artifactCheck(file_get_contents(A::resolve($chat, $first['name'])['path']) === file_get_contents($root.'/report.csv'));
    $directPath = A::directory($chat).'/preview.png';
    file_put_contents($directPath, 'image');
    $direct = A::publish($chat, $directPath);
    artifactCheck($direct['name'] === 'preview.png');
    artifactCheck(count(A::listing($chat)) === 3);
    artifactCheck(A::publish($chat, $directPath) === $direct);
    artifactCheck(count(A::listing($chat)) === 3);
    foreach (['../report.csv', '.secret', 'missing', "bad\0file"] as $bad) artifactCheck(A::resolve($chat, $bad) === null);
    artifactCheck(A::resolve('../escape', $first['name']) === null);
    symlink($root.'/report.csv', A::directory($chat).'/escape.csv');
    artifactCheck(A::resolve($chat, 'escape.csv') === null);
    try { A::publish($chat, $root.'/missing'); throw new RuntimeException('Missing file accepted'); } catch (InvalidArgumentException) {}
    echo "Artifact checks passed\n";
} finally {
    foreach (glob($root.'/.artifacts/'.$chat.'/*') ?: [] as $file) unlink($file);
    if (is_dir($root.'/.artifacts/'.$chat)) rmdir($root.'/.artifacts/'.$chat);
    if (is_dir($root.'/.artifacts')) rmdir($root.'/.artifacts');
    unlink($root.'/report.csv'); rmdir($root);
}
