<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Service\{ProjectWorkspace as W, Store as S};

$root = sys_get_temp_dir().'/crabase-code-'.bin2hex(random_bytes(8));
$db = tempnam(sys_get_temp_dir(), 'crabase-code-db-');
mkdir($root, 0700); mkdir($root.'/project'); mkdir($root.'/outside');
putenv('CRABASE_WORKSPACE_ROOT='.$root); putenv('CRABASE_DB='.$db);
function workspaceCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Project workspace check failed.'); }
function command(array $args, string $cwd): void {
    $process = proc_open($args, [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes, $cwd);
    workspaceCheck(is_resource($process) && proc_close($process) === 0);
}
try {
    command([PHP_BINARY, dirname(__DIR__).'/cua', 'migrate'], dirname(__DIR__));
    command(['git','init','--quiet'], $root.'/project');
    command(['git','config','user.email','test@example.com'], $root.'/project');
    command(['git','config','user.name','Test'], $root.'/project');
    file_put_contents($root.'/project/readme.md', "before\n");
    command(['git','add','readme.md'], $root.'/project');
    command(['git','commit','--quiet','-m','Initial'], $root.'/project');
    file_put_contents($root.'/project/readme.md', "after\n");
    file_put_contents($root.'/project/new.php', "<?php echo 'new';\n");
    S::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', ['project', 'Project', $root.'/project']);
    $data = ['project_id'=>'project'];
    $context = W::context($data);
    workspaceCheck($context['branch'] !== null && !$context['detached'] && $context['worktree'] === null);
    workspaceCheck($context['path'] === realpath($root.'/project'));
    command(['git','worktree','add','--quiet','-b','context-test',$root.'/linked'], $root.'/project');
    S::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', ['linked', 'Linked', $root.'/linked']);
    $linked = W::context(['project_id'=>'linked']);
    workspaceCheck($linked['branch'] === 'context-test' && $linked['worktree'] === 'linked');
    command(['git','checkout','--quiet','--detach'], $root.'/linked');
    $detached = W::context(['project_id'=>'linked']);
    workspaceCheck($detached['detached'] && preg_match('/^[a-f0-9]{7,}$/', $detached['branch']));
    command(['git','worktree','remove',$root.'/linked'], $root.'/project');
    S::run('INSERT INTO projects (id,name,path) VALUES (?,?,?)', ['plain', 'Plain', $root.'/outside']);
    workspaceCheck(W::context(['project_id'=>'plain'])['branch'] === null);
    $snapshot = W::snapshot($data);
    workspaceCheck($snapshot['git'] === true);
    workspaceCheck($snapshot['paths'] === ['new.php','readme.md']);
    workspaceCheck(array_column($snapshot['changes'], 'status', 'path') === ['new.php'=>'untracked','readme.md'=>'modified']);
    file_put_contents($root.'/project/.gitignore', ".env\n.cache/\n");
    file_put_contents($root.'/project/.env', "TEST=1\n");
    mkdir($root.'/project/.cache');
    file_put_contents($root.'/project/.cache/data', 'cached');
    file_put_contents($root.'/project/.hidden', 'visible');
    $snapshot = W::snapshot($data);
    foreach (['.gitignore', '.env', '.cache/data', '.hidden'] as $path) {
        workspaceCheck(in_array($path, $snapshot['paths'], true));
    }
    workspaceCheck($snapshot['ignored'] === ['.cache/data', '.env']);
    workspaceCheck(!array_intersect($snapshot['ignored'], array_column($snapshot['changes'], 'path')));
    workspaceCheck(!array_filter($snapshot['paths'], fn($path) => str_starts_with($path, '.git/')));
    workspaceCheck(W::file($data + ['path'=>'.env'])['contents'] === "TEST=1\n");
    mkdir($root.'/outside/.hidden');
    file_put_contents($root.'/outside/.hidden/file', 'visible');
    $plain = W::snapshot(['project_id'=>'plain']);
    workspaceCheck($plain['paths'] === ['.hidden/', '.hidden/file'] && $plain['ignored'] === []);
    $file = W::file($data + ['path'=>'readme.md']);
    workspaceCheck($file['contents'] === "after\n" && strlen($file['hash']) === 64);
    workspaceCheck(str_contains(W::diff($data + ['path'=>'readme.md'])['patch'], '+after'));
    workspaceCheck(str_contains(W::diff($data + ['path'=>'new.php'])['patch'], "+<?php echo 'new';"));
    $saved = W::save($data + ['path'=>'readme.md','contents'=>"saved\n",'hash'=>$file['hash']]);
    workspaceCheck(file_get_contents($root.'/project/readme.md') === "saved\n" && strlen($saved['hash']) === 64);
    try { W::save($data + ['path'=>'readme.md','contents'=>'stale','hash'=>$file['hash']]); throw new RuntimeException('Stale write accepted.'); }
    catch (InvalidArgumentException) {}
    symlink($root.'/outside', $root.'/project/escape');
    foreach (['../outside/file', '/etc/passwd', 'escape/file'] as $path) {
        try { W::file($data + ['path'=>$path]); throw new RuntimeException('Invalid path accepted.'); }
        catch (InvalidArgumentException) {}
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5XcAAAAASUVORK5CYII=');
    file_put_contents($root.'/project/image.png', $png);
    $preview = W::file($data + ['path'=>'image.png']);
    workspaceCheck($preview['image'] === 'data:image/png;base64,'.base64_encode($png));
    workspaceCheck($preview['contents'] === '' && $preview['hash'] === hash('sha256', $png));
    file_put_contents($root.'/project/image.png', "not an image\0");
    workspaceCheck(W::file($data + ['path'=>'image.png'])['unsupported'] === 'Unsupported file');
    file_put_contents($root.'/project/image.png', str_repeat('x', 5000000));
    clearstatcache();
    workspaceCheck(strlen(W::file($data + ['path'=>'image.png'])['contents']) === 5000000);
    file_put_contents($root.'/project/image.png', str_repeat('x', 5000001));
    clearstatcache();
    workspaceCheck(isset(W::file($data + ['path'=>'image.png'])['unsupported']));
    echo "PASS: project file listing, Git status and diffs, text/image previews, and path boundaries.\n";
} finally {
    foreach (['project/.gitignore', 'project/.env', 'project/.hidden', 'project/.cache/data', 'outside/.hidden/file'] as $path) {
        if (is_file($root.'/'.$path)) unlink($root.'/'.$path);
    }
    foreach (['project/.cache', 'outside/.hidden'] as $path) if (is_dir($root.'/'.$path)) rmdir($root.'/'.$path);
    if (is_link($root.'/project/escape')) unlink($root.'/project/escape');
    foreach ([$root.'/project/new.php',$root.'/project/readme.md',$root.'/project/image.png'] as $file) if (is_file($file)) unlink($file);
    $git = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/project/.git', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($git as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    if (is_dir($root.'/project/.git')) rmdir($root.'/project/.git');
    rmdir($root.'/project'); rmdir($root.'/outside'); rmdir($root);
    foreach ([$db,$db.'-wal',$db.'-shm'] as $file) if (is_file($file)) unlink($file);
}
