<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\{Actions, ProjectWorkspace, WorkspaceFolders, Store};

$root = sys_get_temp_dir().'/crabase-worktrees-'.bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root.'/repo');
$root = realpath($root);
putenv('CRABASE_WORKSPACE_ROOT='.$root); putenv('CRABASE_DB='.$root.'/test.sqlite');
function worktreeCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Worktree check failed.'); }
function worktreeDenied(callable $action): void {
    try { $action(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Expected rejection.');
}
try {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phinx', 'migrate', '-c', dirname(__DIR__).'/phinx.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR], $pipes);
    worktreeCheck(proc_close($process) === 0);
    foreach ([['init','--quiet'], ['config','user.name','Test'], ['config','user.email','test@example.com']] as $args) worktreeCheck(ProjectWorkspace::command($root.'/repo', $args)[0] === 0);
    file_put_contents($root.'/repo/readme.txt', "original\n");
    ProjectWorkspace::command($root.'/repo', ['add', 'readme.txt']);
    worktreeCheck(ProjectWorkspace::command($root.'/repo', ['commit', '--quiet', '-m', 'Initial'])[0] === 0);
    $parent = Actions::handle('project', ['path'=>$root.'/repo'])['id'];
    file_put_contents($root.'/repo/readme.txt', "uncommitted\n");
    file_put_contents($root.'/repo/.env', 'SECRET=not-copied');
    foreach (['../escape', '-bad', 'a..b', 'feature//bad', 'test;touch hacked'] as $branch) worktreeDenied(fn()=>Actions::handle('worktreeCreate', ['project_id'=>$parent, 'branch'=>$branch]));
    symlink($root.'/repo', $root.'/.worktrees');
    worktreeDenied(fn()=>Actions::handle('worktreeCreate', ['project_id'=>$parent, 'branch'=>'feature/symlink']));
    unlink($root.'/.worktrees');
    $id = Actions::handle('worktreeCreate', ['project_id'=>$parent, 'branch'=>'feature/login'])['id'];
    $project = Store::connection()->table('projects')->where('id', $id)->first();
    $path = ProjectWorkspace::workspacePath($project);
    worktreeCheck(dirname($path) === $root.'/.worktrees/repo' && preg_match('/^[a-z]+-[a-z]+$/D', basename($path)) && $project['parent_id'] === $parent);
    worktreeCheck(app\service\Worktrees::projectFolder('daudau.cc') === 'daudau.cc');
    worktreeCheck(app\service\Worktrees::projectFolder('../My Project/') === 'my-project');
    $another = Actions::handle('worktreeCreate', ['project_id'=>$parent, 'branch'=>'feature/other'])['id'];
    worktreeCheck(ProjectWorkspace::workspacePath(Store::connection()->table('projects')->where('id', $another)->first()) !== $path);
    $legacyId = 'abc123abc123abcd';
    mkdir($root.'/.worktrees/'.$parent);
    $legacyPath = $root.'/.worktrees/'.$parent.'/'.$legacyId;
    worktreeCheck(ProjectWorkspace::command($root.'/repo', ['worktree','add','-b','feature/legacy','--',$legacyPath,'HEAD'])[0] === 0);
    Store::connection()->table('projects')->insert(['id'=>$legacyId,'parent_id'=>$parent,'name'=>'feature/legacy','path'=>$legacyPath]);
    worktreeCheck(ProjectWorkspace::workspacePath(Store::connection()->table('projects')->where('id', $legacyId)->first()) === $legacyPath);
    rename($path, $path.'-saved'); symlink($root.'/repo', $path);
    worktreeDenied(fn()=> ProjectWorkspace::workspacePath($project));
    unlink($path); rename($path.'-saved', $path);
    worktreeCheck(!file_exists($path.'/.env') && file_get_contents($path.'/readme.txt') === "original\n");
    worktreeCheck(ProjectWorkspace::context(['project_id'=>$id])['branch'] === 'feature/login');
    $file = ProjectWorkspace::file(['project_id'=>$id, 'path'=>'readme.txt']);
    ProjectWorkspace::save(['project_id'=>$id, 'path'=>'readme.txt', 'contents'=>"feature\n", 'hash'=>$file['hash']]);
    worktreeCheck(file_get_contents($root.'/repo/readme.txt') === "uncommitted\n");
    worktreeCheck(str_contains(ProjectWorkspace::diff(['project_id'=>$id,'path'=>'readme.txt'])['patch'], '+feature'));
    worktreeDenied(fn()=>Actions::handle('project', ['path'=>$path]));
    worktreeCheck(!in_array('.worktrees', array_column(WorkspaceFolders::listing([])['folders'], 'name')));
    worktreeDenied(fn()=>Actions::handle('worktreeCreate', ['project_id'=>$parent, 'branch'=>'feature/login']));
    worktreeDenied(fn()=>Actions::handle('worktreeCreate', ['project_id'=>$id, 'branch'=>'nested']));
    $a = Actions::handle('create', ['project_id'=>$id])['id'];
    $b = Actions::handle('create', ['project_id'=>$id])['id'];
    worktreeCheck(ProjectWorkspace::workspacePath(Store::connection()->table('projects')->where('id', $id)->first()) === $path && ProjectWorkspace::workspacePath(Store::connection()->table('projects')->where('id', $id)->first()) === $path);
    $jobId = Store::connection()->table('jobs')->insert(['chat_id'=>$a, 'prompt'=>'test']);
    $active = Store::connection()->table('jobs as active')->selectRaw('1')->whereColumn('active.chat_id', 'j.chat_id')->where('active.status', 'running');
    $earlier = Store::connection()->table('jobs as earlier')->selectRaw('1')->whereColumn('earlier.chat_id', 'j.chat_id')->where('earlier.status', 'queued')->where('earlier.cancel', 0)->whereColumn('earlier.id', '<', 'j.id');
    $nextJob = Store::connection()->table('jobs as j')->select('j.*', 'p.path')->join('chats as c', 'c.id', '=', 'j.chat_id')->leftJoin('projects as p', 'p.id', '=', 'c.project_id')->where('j.status', 'queued')->where('j.cancel', 0)->whereNotExists($active)->whereNotExists($earlier)->orderBy('j.id')->limit(1)->get()[0];
    worktreeCheck($nextJob['path'] === $path);
    worktreeDenied(fn()=>Actions::handle('projectDelete', ['project_id'=>$parent]));
    worktreeDenied(fn()=>Actions::handle('projectDelete', ['project_id'=>$id]));
    Store::connection()->table('jobs')->where('id', $jobId)->delete();
    Actions::handle('projectArchive', ['project_id'=>$parent, 'archived'=>true]);
    worktreeCheck(is_dir($path));
    worktreeCheck(Actions::handle('projectDelete', ['project_id'=>$id])['requires_confirmation'] === true);
    worktreeCheck(Store::connection()->table('projects')->where('id', $id)->exists() && is_file($path.'/readme.txt'));
    file_put_contents($path.'/.gitignore', "local.sqlite\n");
    ProjectWorkspace::command($path, ['add', 'readme.txt', '.gitignore']);
    worktreeCheck(ProjectWorkspace::command($path, ['commit', '--quiet', '-m', 'Feature'])[0] === 0);
    file_put_contents($path.'/local.sqlite', 'disposable ignored test data');
    file_put_contents($path.'/untracked.txt', 'keep me');
    worktreeCheck(Actions::handle('projectDelete', ['project_id'=>$id])['requires_confirmation'] === true);
    unlink($path.'/untracked.txt');
    ProjectWorkspace::command($root.'/repo', ['worktree', 'lock', $path]);
    worktreeDenied(fn()=>Actions::handle('projectDelete', ['project_id'=>$id]));
    worktreeDenied(fn()=>Actions::handle('projectDelete', ['project_id'=>$id, 'force'=>true]));
    ProjectWorkspace::command($root.'/repo', ['worktree', 'unlock', $path]);
    file_put_contents($path.'/readme.txt', 'discarded only with confirmation');
    file_put_contents($path.'/untracked.txt', 'discarded too');
    worktreeDenied(fn()=>Actions::handle('projectDelete', ['project_id'=>$id, 'force'=>'yes']));
    Actions::handle('projectDelete', ['project_id'=>$id, 'force'=>true]);
    foreach ([$another, $legacyId] as $worktreeId) Actions::handle('projectDelete', ['project_id'=>$worktreeId]);
    worktreeCheck(!is_dir($path) && ProjectWorkspace::command($root.'/repo', ['show-ref','--verify','--quiet','refs/heads/feature/login'])[0] === 0);
    Actions::handle('projectDelete', ['project_id'=>$parent]);
    worktreeCheck(!Store::connection()->table('projects')->where('id', $id)->exists() && !Store::connection()->table('chats')->where('id', $a)->exists() && !Store::connection()->table('chats')->where('id', $b)->exists() && is_file($root.'/repo/readme.txt'));
    echo "PASS: worktree creation, paths, independent edits, dirty/locked protection, local removal, branch and original-folder preservation.\n";
} finally {
    // Only the disposable test root created above is removed, including its worktrees.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
