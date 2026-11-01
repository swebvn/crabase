<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\WorkspaceFolders as F;

$root = sys_get_temp_dir().'/crabase-folders-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
putenv('CRABASE_WORKSPACE_ROOT='.$root);
$outside = $root.'-outside';
mkdir($outside);
function folderCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Folder check failed'); }
try {
    foreach (['Alpha','Beta','Alpha/Nested','.artifacts','.hidden'] as $folder) mkdir($root.'/'.$folder);
    file_put_contents($root.'/plain.txt','not a directory');
    symlink($outside, $root.'/escape');
    $listing = F::listing([]);
    folderCheck(array_column($listing['folders'],'name') === ['Alpha','Beta']);
    folderCheck($listing['parent'] === dirname($root));
    $nested = F::listing(['path'=>$root.'/Alpha']);
    folderCheck(array_column($nested['folders'],'name') === ['Nested']);
    folderCheck($nested['parent'] === realpath($root));
    folderCheck(F::resolve($outside) === realpath($outside));
    $created = F::create(['path'=>$outside, 'name'=>'Created']);
    folderCheck($created['path'] === realpath($outside.'/Created') && is_dir($created['path']));
    foreach ([ $root, $root.'/escape', $root.'/.artifacts', $root.'/.hidden', $root.'/plain.txt', "bad\0path", $outside.'/Created/name'] as $bad) {
        try { F::resolve($bad); throw new RuntimeException('Invalid folder accepted'); }
        catch (InvalidArgumentException) {}
    }
    folderCheck(F::resolve($root.'/Alpha') === realpath($root.'/Alpha'));
    foreach (['.hidden', 'nested/name', "bad\0name"] as $name) {
        try { F::create(['path'=>$outside, 'name'=>$name]); throw new RuntimeException('Invalid folder name accepted'); }
        catch (InvalidArgumentException) {}
    }
    echo "PASS: workspace folder listing, arbitrary paths, folder creation, hidden paths and symlink boundaries.\n";
} finally {
    unlink($root.'/escape'); unlink($root.'/plain.txt');
    if (isset($created['path']) && is_dir($created['path'])) rmdir($created['path']);
    foreach (['Alpha/Nested','Alpha','Beta','.artifacts','.hidden'] as $folder) rmdir($root.'/'.$folder);
    rmdir($root); rmdir($outside);
}
