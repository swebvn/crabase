<?php

declare(strict_types=1);

namespace App\Process;

use App\Service\{Input, ProjectWorkspace};
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Browser terminals backed by one Node PTY host (bin/terminal-host.mjs), started on first use.
 * Callers authorize the chat; packets go out through the injected chat broadcaster.
 */
final class TerminalHost
{
    private mixed $process = null;
    private array $pipes = [];
    private string $input = '';
    private string $output = '';
    private array $terminals = [];
    private int $sequence = 0;

    /** @param Closure(string, array): void $broadcast sends a packet to everyone viewing a chat */
    public function __construct(private readonly StreamWatchers $watchers, private readonly Closure $broadcast) {}

    public function action(string $chatId, string $action, array $data): array
    {
        if ($action === 'terminalOpen') return ['terminal' => $this->open($chatId, $data)];
        $id = Input::text($data['terminal_id'] ?? null, 64);
        $terminal = $this->terminals[$id] ?? null;
        if (!$terminal || $terminal['chat_id'] !== $chatId) throw new InvalidArgumentException('Terminal not found.');
        if (!$terminal['running'] && $action !== 'terminalClose') throw new InvalidArgumentException('This terminal has exited.');
        if ($action === 'terminalInput') {
            $input = $data['input'] ?? null;
            if (!is_string($input) || $input === '' || strlen($input) > 16384) {
                throw new InvalidArgumentException('Terminal input must be between 1 and 16384 bytes.');
            }
            $this->send(['action' => 'input','id' => $id,'data' => $input]);
        } elseif ($action === 'terminalResize') {
            [$cols, $rows] = $this->size($data);
            $this->send(['action' => 'resize','id' => $id,'cols' => $cols,'rows' => $rows]);
        } elseif ($action === 'terminalClose') {
            $this->send(['action' => 'close','id' => $id]);
            unset($this->terminals[$id]);
            $this->broadcast($chatId, ['event' => 'closed','terminal_id' => $id]);
        } else {
            throw new InvalidArgumentException('Unknown terminal action.');
        }
        return ['ok' => true];
    }

    public function list(?string $chatId): array
    {
        return array_values(array_map(
            fn ($terminal) => array_merge($terminal, ['output' => substr($terminal['output'], -200000)]),
            array_filter($this->terminals, fn ($terminal) => $terminal['chat_id'] === $chatId),
        ));
    }

    public function runningCount(): int
    {
        return count(array_filter($this->terminals, static fn (array $terminal): bool => $terminal['running']));
    }

    /** @param list<string> $chatIds */
    public function runningIn(array $chatIds): bool
    {
        foreach ($this->terminals as $terminal) {
            if ($terminal['running'] && in_array($terminal['chat_id'], $chatIds, true)) return true;
        }
        return false;
    }

    public function hostRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /** Called from the worker tick: notices a host that exited between pipe events. */
    public function check(): void
    {
        if (is_resource($this->process) && !proc_get_status($this->process)['running']) $this->failed('Terminal host stopped.');
    }

    public function shutdown(): void
    {
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) {
            $this->watchers->unwatchReadable($pipe);
            $this->watchers->unwatchWritable($pipe);
            fclose($pipe);
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = [];
        $this->input = '';
        $this->output = '';
    }

    private function open(string $chatId, array $data): array
    {
        $cwd = realpath(ProjectWorkspace::chatPath($chatId));
        if (!$cwd || !is_dir($cwd) || !is_readable($cwd)) throw new InvalidArgumentException('The project folder is unavailable.');
        [$cols, $rows] = $this->size($data);
        $id = bin2hex(random_bytes(8));
        $this->terminals[$id] = ['id' => $id,'chat_id' => $chatId,'title' => 'Terminal '.(++$this->sequence),'output' => '','running' => true];
        try {
            $this->send(['action' => 'open','id' => $id,'cwd' => $cwd,'cols' => $cols,'rows' => $rows]);
        } catch (\Throwable $error) {
            unset($this->terminals[$id]);
            throw $error;
        }
        $this->broadcast($chatId, ['event' => 'opened','terminal' => $this->terminals[$id]]);
        return $this->terminals[$id];
    }

    private function broadcast(string $chatId, array $packet): void
    {
        ($this->broadcast)($chatId, $packet + ['type' => 'terminal', 'chat_id' => $chatId]);
    }

    private function size(array $data): array
    {
        $cols = $data['cols'] ?? 80;
        $rows = $data['rows'] ?? 24;
        if (!is_int($cols) || !is_int($rows) || $cols < 20 || $cols > 500 || $rows < 2 || $rows > 200) {
            throw new InvalidArgumentException('Invalid terminal size.');
        }
        return [$cols, $rows];
    }

    private function send(array $message): void
    {
        if (!$this->process) {
            $root = dirname(__DIR__, 3);
            $this->process = proc_open([getenv('NODE_BIN') ?: 'node',$root.'/server/bin/terminal-host.mjs'], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $this->pipes, $root);
            if (!is_resource($this->process)) {
                $this->process = null;
                throw new RuntimeException('Could not start the terminal host.');
            }
            foreach ($this->pipes as $pipe) stream_set_blocking($pipe, false);
            // PTY input/output must not wait for the agent's 50 ms scheduling tick.
            foreach ([1, 2] as $index) $this->watchers->readable($this->pipes[$index], fn () => $this->pump());
        }
        $this->output .= json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        $this->tick();
    }

    private function pump(): void
    {
        try {
            $this->tick();
        } catch (\Throwable $error) {
            $this->failed($error->getMessage());
        }
    }

    private function tick(): void
    {
        if (!is_resource($this->process)) return;
        if (!proc_get_status($this->process)['running']) throw new RuntimeException('Terminal host stopped.');
        if ($this->output !== '') {
            $written = fwrite($this->pipes[0], $this->output);
            if ($written === false) throw new RuntimeException('Terminal host connection closed.');
            $this->output = substr($this->output, $written);
        }
        if ($this->output === '') $this->watchers->unwatchWritable($this->pipes[0]);
        else $this->watchers->writable($this->pipes[0], fn () => $this->pump());
        $stderr = stream_get_contents($this->pipes[2]);
        if ($stderr) file_put_contents(dirname(__DIR__, 2).'/runtime/logs/terminal.log', $stderr, FILE_APPEND);
        if (feof($this->pipes[2])) $this->watchers->unwatchReadable($this->pipes[2]);
        $this->input .= stream_get_contents($this->pipes[1]);
        while (($end = strpos($this->input, "\n")) !== false) {
            $line = substr($this->input, 0, $end);
            $this->input = substr($this->input, $end + 1);
            $message = json_decode($line, true);
            $id = $message['id'] ?? null;
            if (!is_array($message) || !$id || !isset($this->terminals[$id])) continue;
            $chatId = $this->terminals[$id]['chat_id'];
            if ($message['event'] === 'output' && is_string($message['data'] ?? null)) {
                $this->terminals[$id]['output'] .= $message['data'];
                if (strlen($this->terminals[$id]['output']) > 1000000) {
                    $this->terminals[$id]['output'] = substr($this->terminals[$id]['output'], -1000000);
                }
                $this->broadcast($chatId, ['event' => 'output','terminal_id' => $id,'data' => $message['data']]);
            } elseif ($message['event'] === 'exit') {
                unset($this->terminals[$id]);
                $this->broadcast($chatId, ['event' => 'closed','terminal_id' => $id]);
            } elseif ($message['event'] === 'error') {
                $this->terminals[$id]['running'] = false;
                $this->broadcast($chatId, ['event' => 'error','terminal_id' => $id,'message' => $message['message'] ?? 'Terminal failed.']);
            }
        }
        if (feof($this->pipes[1])) throw new RuntimeException('Terminal host connection closed.');
    }

    private function failed(string $message): void
    {
        foreach ($this->terminals as &$terminal) {
            $terminal['running'] = false;
            $this->broadcast($terminal['chat_id'], ['event' => 'error','terminal_id' => $terminal['id'],'message' => $message]);
        }
        unset($terminal);
        $this->shutdown();
    }
}
