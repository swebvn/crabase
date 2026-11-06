<?php

declare(strict_types=1);

namespace App\Process;

use Crab\Engine\EventLoop;

/** Runs a short command off the event loop and hands its stdout to a callback when it exits or times out. */
final class ChildProcess
{
    /** @param \Closure(string $output, ?string $failure): void $done */
    public static function run(EventLoop $loop, StreamWatchers $watchers, array $command, float $timeout, int $maxOutput, \Closure $done, ?string $errorLog = null): void
    {
        $pipes = [];
        $process = @proc_open($command, [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>$errorLog === null ? ['file','/dev/null','w'] : ['file',$errorLog,'a']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start a worker process.');
        $stdout = $pipes[1];
        stream_set_blocking($stdout, false);
        $output = '';
        $timer = null;
        $finish = function (?string $failure = null) use (&$output, &$timer, $stdout, $process, $loop, $watchers, $done): void {
            if ($timer !== null) $loop->cancel($timer);
            $timer = null;
            $watchers->unwatchReadable($stdout);
            fclose($stdout);
            if ($failure !== null) proc_terminate($process, 9);
            proc_close($process);
            $done($output, $failure);
        };
        $timer = $loop->delay($timeout, function () use (&$timer, $finish): void { $timer = null; $finish('timeout'); });
        $watchers->readable($stdout, function () use (&$output, $stdout, $maxOutput, $finish): void {
            $chunk = fread($stdout, 65536);
            if ($chunk !== false) $output .= $chunk;
            if (strlen($output) > $maxOutput) $finish('too large');
            elseif (feof($stdout)) $finish();
        });
    }
}
