#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use app\service\Updater;

$root=dirname(__DIR__,2); $tag='';
foreach (array_slice($argv,1) as $argument) if (str_starts_with($argument,'--tag=')) $tag=substr($argument,6);
if (!Updater::validTag($tag)) exit("Invalid release tag.\n");
$lockPath=$root.'/server/storage/update.lock'; if (!is_dir(dirname($lockPath))) mkdir(dirname($lockPath),0770,true);
$lock=fopen($lockPath,'c'); if (!$lock || !flock($lock,LOCK_EX)) exit("Another update is already running.\n");
$status=['state'=>'running','tag'=>$tag,'started_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'message'=>'Preparing update.'];
$write=static function(string $message,string $state='running',array $extra=[]) use (&$status): void { $status=array_merge($status,$extra,['state'=>$state,'message'=>$message,'updated_at'=>gmdate('c')]); Updater::writeStatus($status); };
$run=static function(array $command,string $message) use($root,$write): string { $write($message); $pipes=[]; $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root); if(!is_resource($process)) throw new RuntimeException('Could not run '.$command[0].'; install it or add it to the service PATH.'); $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process); if($code!==0) { $detail=trim($error?:$output); throw new RuntimeException($message.' failed.'.($detail!==''?' '.substr($detail,-1200):'')); } return trim($output); };
$config = require $root . '/server/config/crabase.php';   // loads .env (NODE_BIN, CRABASE_DB) into the environment
$node = getenv('NODE_BIN') ?: 'node';
$npm = dirname($node) . '/npm';
if (!is_file($npm) || !is_executable($npm)) $npm = 'npm';
// npm and package scripts start with "#!/usr/bin/env node"; nvm-style installs need NODE_BIN's folder on PATH.
if (is_file($node)) putenv('PATH=' . dirname($node) . ':' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'));
$previous=$run(['git','rev-parse','HEAD'],'Reading the current commit.');
$backupDirectory = null; $database = null; $started = false; $stopped = false; $checkedOut = false;
try {
    $run(['git','fetch','origin','refs/tags/'.$tag.':refs/tags/'.$tag],'Fetching '.$tag.'.');
    $target=$run(['git','rev-parse','refs/tags/'.$tag.'^{commit}'],'Verifying the release tag.');
    if(!preg_match('/^[0-9a-f]{40}$/',$target)) throw new RuntimeException('The release tag does not resolve to a commit.');
    $run(['git','diff','--quiet','HEAD'],'Checking for local changes.');
    $run(['git','diff','--cached','--quiet'],'Checking the index.');
    if ($run(['git','status','--porcelain','--untracked-files=no'],'Checking the checkout.') !== '') throw new RuntimeException('The deployment checkout has local changes.');
    // Fail before stopping the service if a build tool is missing from the service PATH.
    $run(['composer','--version'],'Checking Composer.');
    $run([$npm,'--version'],'Checking npm.');
    $database=(string)$config['database_path'];
    $run([PHP_BINARY,'server/cua','stop','--timeout=30'],'Stopping Crabase before the update.');
    $stopped = true;
    $backupDirectory=$root.'/server/runtime/update-backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)); mkdir($backupDirectory,0700,true);
    $write('Creating a database backup.','running',['backup'=>'server/runtime/update-backups/'.basename($backupDirectory)]);
    if(is_file($database)) { $pdo=new PDO('sqlite:'.$database); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->exec('VACUUM INTO '.$pdo->quote($backupDirectory.'/crabase.sqlite')); }
    foreach([$root.'/.env',$root.'/server/runtime/avatars'] as $source) { if(is_file($source)) copy($source,$backupDirectory.'/'.basename($source)); elseif(is_dir($source)) exec('cp -a '.escapeshellarg($source).' '.escapeshellarg($backupDirectory.'/'.basename($source))); }
    if (is_dir($root . '/server/public')) exec('cp -a ' . escapeshellarg($root . '/server/public') . ' ' . escapeshellarg($backupDirectory . '/public'));
    file_put_contents($backupDirectory.'/previous-commit',$previous."\n");
    $checkedOut = true;
    $run(['git','checkout','--detach',$tag],'Installing release '.$tag.'.');
    $run(['composer','install','--working-dir=server','--no-dev','--optimize-autoloader'],'Installing PHP dependencies.');
    $run([$npm,'ci'],'Installing frontend and terminal dependencies.');
    $run([$npm,'run','build'],'Building the frontend.');
    $run([PHP_BINARY,'server/cua','migrate'],'Applying database migrations.');
    $run([PHP_BINARY,'server/cua','start','-d'],'Starting the updated Crabase.');
    $started = true;
    $write('Update completed. Reconnecting to the new release.','succeeded',['finished_at'=>gmdate('c')]);
} catch(Throwable $error) {
    $message=$error->getMessage();
    if (!$started && $stopped && preg_match('/^[0-9a-f]{40}$/', $previous)) {
        $rollbackPipes = [];
        $rollback = @proc_open(['git','checkout','--detach',$previous],[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$rollbackPipes,$root);
        if (is_resource($rollback)) @proc_close($rollback);
        // The new release may have replaced vendor/ and node_modules/; reinstall the previous lockfiles.
        if ($checkedOut) {
            try {
                $run(['composer','install','--working-dir=server','--no-dev','--optimize-autoloader'],'Restoring previous PHP dependencies.');
                $run([$npm,'ci'],'Restoring previous frontend and terminal dependencies.');
            } catch (Throwable $restoreError) { $message .= ' Dependency restore failed: ' . $restoreError->getMessage(); }
        }
        if (isset($backupDirectory) && is_dir($backupDirectory . '/public')) {
            exec('rm -rf ' . escapeshellarg($root . '/server/public') . ' && cp -a ' . escapeshellarg($backupDirectory . '/public') . ' ' . escapeshellarg($root . '/server/public'));
        }
        if ($database !== null && is_file((string)$backupDirectory . '/crabase.sqlite')) {
            @unlink($database . '-wal'); @unlink($database . '-shm');
            copy((string)$backupDirectory . '/crabase.sqlite', $database);
        }
        try { $run([PHP_BINARY,'server/cua','start','-d'],'Restoring the previous Crabase.'); } catch (Throwable $restoreError) { $message .= ' Restore failed: ' . $restoreError->getMessage(); }
    }
    $write('Update failed: '.$message,'failed',['finished_at'=>gmdate('c'),'error'=>$message]); file_put_contents($root.'/server/storage/update.log','['.gmdate('c').'] '.$message."\n",FILE_APPEND); exit(1);
}
flock($lock,LOCK_UN); fclose($lock);
