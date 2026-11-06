<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Service\WorkspaceFolders as F;

$root = sys_get_temp_dir().'/crabase-folders-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
putenv('CRABASE_WORKSPACE_ROOT='.$root);
$outside = $root.'-outside';
mkdir($outside);
function folderCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Folder check failed'); }
try {
    foreach (['Alpha','Beta','Alpha/Nested','.artifacts','.hidden','.worktrees','.worktrees/repo'] as $folder) mkdir($root.'/'.$folder);
    file_put_contents($root.'/plain.txt','not a directory');
    symlink($outside, $root.'/escape');
    $listing = F::listing([]);
    // Hidden folders are listed after visible ones; Crabase's internal folders are not.
    folderCheck(array_column($listing['folders'],'name') === ['Alpha','Beta','.hidden']);
    folderCheck($listing['parent'] === dirname($root));
    $nested = F::listing(['path'=>$root.'/Alpha']);
    folderCheck(array_column($nested['folders'],'name') === ['Nested']);
    folderCheck($nested['parent'] === realpath($root));
    folderCheck(F::resolve($outside) === realpath($outside));
    $created = F::create(['path'=>$outside, 'name'=>'Created']);
    folderCheck($created['path'] === realpath($outside.'/Created') && is_dir($created['path']));
    foreach ([ $root, $root.'/escape', $root.'/.artifacts', $root.'/.worktrees', $root.'/.worktrees/repo', $root.'/plain.txt', "bad\0path", $outside.'/Created/name'] as $bad) {
        try { F::resolve($bad); throw new RuntimeException('Invalid folder accepted'); }
        catch (InvalidArgumentException) {}
    }
    folderCheck(F::resolve($root.'/Alpha') === realpath($root.'/Alpha'));
    // Hidden folders and paths through them (like ~/.openclaw/workspace) are ordinary projects.
    folderCheck(F::resolve($root.'/.hidden') === realpath($root.'/.hidden'));
    $dotted = F::create(['path'=>$outside, 'name'=>'.tool']);
    $inside = F::create(['path'=>$dotted['path'], 'name'=>'workspace']);
    folderCheck(F::resolve($outside.'/.tool/workspace') === $inside['path']);
    folderCheck(array_column(F::listing(['path'=>$outside.'/.tool'])['folders'],'name') === ['workspace']);
    foreach (['', '.', '..', 'nested/name', "bad\0name"] as $name) {
        try { F::create(['path'=>$outside, 'name'=>$name]); throw new RuntimeException('Invalid folder name accepted'); }
        catch (InvalidArgumentException) {}
    }
    echo "PASS: workspace folder listing, arbitrary and hidden paths, folder creation, internal-folder and symlink boundaries.\n";
} finally {
    unlink($root.'/escape'); unlink($root.'/plain.txt');
    if (isset($created['path']) && is_dir($created['path'])) rmdir($created['path']);
    foreach (['.tool/workspace', '.tool'] as $folder) if (is_dir($outside.'/'.$folder)) rmdir($outside.'/'.$folder);
    foreach (['Alpha/Nested','Alpha','Beta','.artifacts','.hidden','.worktrees/repo','.worktrees'] as $folder) rmdir($root.'/'.$folder);
    rmdir($root); rmdir($outside);
}
