<?php

namespace app\process;

use app\service\Store;
use app\service\Auth;
use app\service\ProjectAccess;
use app\model\{Job, Chat, Message, Approval, Setting};
use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

final class Codex
{
    private mixed $process = null;
    private array $pipes = [];
    private array $pending = [];
    private array $jobs = [];
    private string $input = '';
    private string $output = '';
    private string $revision = '';
    private int $sequence = 0;
    private bool $ready = false;
    private float $retryAt = 0;
    private float $started = 0;
    private array $clients = [];
    private ?Worker $worker = null;
    private bool $bootAttempted = false;
    private mixed $terminalProcess = null;
    private array $terminalPipes = [];
    private string $terminalInput = '';
    private string $terminalOutput = '';
    private array $terminals = [];
    private int $terminalSequence = 0;

    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;
        Store::db();
        Store::notify(Job::query()->where('status', 'running')->update(['status'=>'failed']));
        Store::notify(Chat::query()->whereIn('status', ['running','approval'])->update(['status'=>'idle']));
        Store::notify(Approval::query()->whereNull('decision')->update(['decision'=>'decline']));
        foreach (Message::query()->where('role', 'agent_activity')->get(['id','body']) as $row) {
            $activity = json_decode($row['body'], true);
            if (!empty($activity['active'])) {
                Store::notify($row->update(['body'=>json_encode(\app\service\AgentActivity::finish($activity), JSON_INVALID_UTF8_SUBSTITUTE)]));
            }
        }
        $this->status('ready');
        \app\service\Attachments::cleanup();
        Timer::add(3600, fn () => \app\service\Attachments::cleanup());
        // One persistent app-server, with independent turns for different chats.
        Timer::add(0.05, function () {
            $this->tick();
            $this->publish();
        });
        Timer::add(2, function () {
            foreach ($this->clients as $id => $client) {
                if (!Auth::user($client['token']) && isset($this->worker->connections[$id])) {
                    $this->reply($this->worker->connections[$id], ['type'=>'unauthorized']);
                    $this->worker->connections[$id]->close();
                }
            }
        });
    }

    public function onWebSocketConnect(TcpConnection $connection, Request $request): void
    {
        $host = explode(':', $request->host())[0];
        if (!Auth::allowedHost($host) || !Auth::allowedOrigin($request->header('origin'))) {
            $connection->close();
            return;
        }
        $token = $request->cookie(Auth::COOKIE);
        if (!Auth::user($token)) {
            $this->reply($connection, ['type'=>'unauthorized']);
            $connection->close();
            return;
        }
        $connection->maxSendBufferSize = 8 * 1024 * 1024;
        $connection->onBufferFull = fn () => $connection->close();
        $this->clients[$connection->id] = ['token'=>$token, 'chat_id' => null,'state' => null,'thread' => null];
    }

    private function reply(TcpConnection $connection, array $message): void
    {
        $connection->send(json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE));
    }
    public function onClose(TcpConnection $connection): void
    {
        unset($this->clients[$connection->id]);
    }
    public function onMessage(TcpConnection $connection, string $data): void
    {
        if (!isset($this->clients[$connection->id])) {
            $connection->close();
            return;
        }
        $id = null;
        try {
            $actor = Auth::user($this->clients[$connection->id]['token']);
            if (!$actor) {
                $this->reply($connection, ['type'=>'unauthorized']);
                $connection->close();
                return;
            }
            if (strlen($data) > 100000) {
                throw new \InvalidArgumentException('Request too large.');
            }
            $m = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($m) || !is_int($m['id'] ?? null) || !is_string($m['action'] ?? null) || !is_array($m['data'] ?? null)) {
                throw new \InvalidArgumentException('Expected id, action, and data.');
            }
            $id = $m['id'];
            if (!empty($m['data']['project_id'])) ProjectAccess::project($actor, Store::text($m['data']['project_id'], 64));
            if (!empty($m['data']['chat_id'])) ProjectAccess::chat($actor, Store::text($m['data']['chat_id'], 64));
            if ($m['action'] === 'models') {
                if (!$this->process) {
                    $this->boot();
                } elseif ($this->ready) {
                    $this->loadModels();
                }
                $result = ['ok' => true];
            } elseif ($m['action'] === 'sync') {
                $chatId = empty($m['data']['chat_id']) ? null : Store::text($m['data']['chat_id'], 64);
                $after = $m['data']['after'] ?? null;
                $before = $m['data']['before'] ?? null;
                $limit = $m['data']['limit'] ?? 150;
                if ($after !== null && (!is_int($after) || $after < 0)) {
                    throw new \InvalidArgumentException('Invalid sync cursor.');
                }
                if ($before !== null && (!is_int($before) || $before < 0)) throw new \InvalidArgumentException('Invalid history cursor.');
                if (!is_int($limit) || $limit < 1 || $limit > 500) throw new \InvalidArgumentException('Invalid message limit.');
                $thread = $chatId ? Store::threadPage($chatId, $before, $after, $limit) : null;
                $resultThread = $thread;
                $state = ProjectAccess::snapshot(Store::snapshot(), $actor);
                $state['pins'] = \app\model\User::findOrFail($actor['id'])->pinnedProjects()->whereIn('projects.id', array_column($state['projects'], 'id'))->pluck('projects.id')->all();
                $this->clients[$connection->id] = ['token'=>$this->clients[$connection->id]['token'], 'chat_id' => $chatId,'state' => $state,'thread' => $thread];
                $result = ['state' => $state,'thread' => $resultThread,'terminals' => $this->terminalList($chatId)];
            } elseif (str_starts_with($m['action'], 'terminal')) {
                $result = $this->terminalAction($connection, $m['action'], $m['data']);
            } else {
                $m['data']['user_id'] = $actor['id'];
                if ($m['action'] === 'projectDelete') {
                    $chatIds = Chat::query()->where('project_id', Store::text($m['data']['project_id'] ?? null, 64))->pluck('id')->all();
                    foreach ($this->terminals as $terminal) {
                        if ($terminal['running'] && in_array($terminal['chat_id'], $chatIds, true)) throw new \InvalidArgumentException('Close this workspace’s terminals before deleting it.');
                    }
                }
                $result = match ($m['action']) {
                    'profile' => ['user'=>$actor],
                    'projectSharing', 'projectSharingSave' => ProjectAccess::sharing($actor, $m['data'], $m['action'] === 'projectSharingSave'),
                    'project', 'projectFolders', 'projectArchive', 'projectDelete', 'worktreeCreate' => $actor['admin']
                        ? \app\service\Actions::handle($m['action'], $m['data'])
                        : throw new \InvalidArgumentException('Administrator access required to manage projects.'),
                    'profileSave' => Auth::update($actor, $m['data'], false),
                    'usersList' => ['users'=>Auth::users($actor)],
                    'health' => $actor['admin'] ? $this->health() : throw new \InvalidArgumentException('Administrator access required.'),
                    'userCreate' => $actor['admin'] ? Auth::create($m['data']) : throw new \InvalidArgumentException('Administrator access required.'),
                    'userUpdate' => Auth::update($actor, $m['data'], true),
                    default => \app\service\Actions::handle($m['action'], $m['data']),
                };
            }
            $this->reply($connection, ['id' => $id,'result' => $result]);
            $this->publish();
        } catch (\InvalidArgumentException|\JsonException $e) {
            $this->reply($connection, ['id' => $id,'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log((string)$e);
            $this->reply($connection, ['id' => $id,'error' => 'Unable to save changes. Please try again.']);
        }
    }
    // ponytail: compare subscribed history rows; use database cursors if long histories make this slow.
    private function publish(): void
    {
        $revision = Setting::query()->whereKey('revision')->value('value') ?? '';
        if ($revision === $this->revision) {
            return;
        }
        $this->revision = $revision;
        $snapshot = Store::snapshot();
        $threads = [];
        foreach ($this->clients as $id => &$client) {
            if (!$actor = Auth::user($client['token'])) {
                if (isset($this->worker->connections[$id])) {
                    $this->reply($this->worker->connections[$id], ['type'=>'unauthorized']);
                    $this->worker->connections[$id]->close();
                }
                continue;
            }
            if ($client['state'] === null) {
                continue;
            }
            $state = ProjectAccess::snapshot($snapshot, $actor);
            $state['pins'] = \app\model\User::findOrFail($actor['id'])->pinnedProjects()->whereIn('projects.id', array_column($state['projects'], 'id'))->pluck('projects.id')->all();
            $patch = ['type' => 'patch'];
            foreach ($state as $key => $value) {
                if ($value !== $client['state'][$key]) {
                    $patch['state'][$key] = $value;
                }
            }
            $client['state'] = $state;
            if ($client['chat_id'] && !in_array($client['chat_id'], array_column($state['chats'], 'id'), true)) {
                $client['chat_id'] = null;
                $client['thread'] = null;
                $patch['access_revoked'] = true;
            }
            if ($chatId = $client['chat_id']) {
                $thread = $threads[$chatId] ??= Store::thread($chatId);
                $old = array_column($client['thread']['messages'], null, 'id');
                foreach ($thread['messages'] as $message) {
                    $before = $old[$message['id']] ?? null;
                    if ($before === $message) {
                        continue;
                    }
                    if ($before && str_starts_with($message['body'], $before['body'])) {
                        $patch['append'][] = ['id' => $message['id'],'delta' => substr($message['body'], strlen($before['body']))];
                    } else {
                        $patch['messages'][] = $message;
                    }
                }
                if ($thread['artifacts'] !== $client['thread']['artifacts']) {
                    $patch['artifacts'] = $thread['artifacts'];
                }
                if ($thread['approvals'] !== $client['thread']['approvals']) {
                    $patch['approvals'] = $thread['approvals'];
                }
                $patch['chat_id'] = $chatId;
                $client['thread'] = $thread;
            }
            if (isset($patch['state']) || isset($patch['messages']) || isset($patch['append']) || isset($patch['approvals']) || isset($patch['artifacts'])) {
                if (isset($this->worker->connections[$id])) {
                    $this->reply($this->worker->connections[$id], $patch);
                }
            }
        }
        unset($client);
    }

    private function health(): array
    {
        $checks = [['name'=>'WebSocket worker', 'status'=>'ok', 'detail'=>'Responding']];
        $running = is_resource($this->process) && proc_get_status($this->process)['running'];
        $checks[] = ['name'=>'Codex app-server', 'status'=>$running && $this->ready ? 'ok' : 'warning',
            'detail'=>$running ? ($this->ready ? 'Initialized and running' : 'Starting; not ready yet') : ($this->bootAttempted ? 'Not running' : 'Not started yet')];
        try {
            $models = json_decode(Setting::query()->whereKey('models')->value('value') ?? '[]', true);
            $checks[] = ['name'=>'Database', 'status'=>'ok', 'detail'=>'Read query succeeded'];
            $checks[] = ['name'=>'Models', 'status'=>$models ? 'ok' : 'warning', 'detail'=>count($models ?: []).' cached models; provider requests not tested'];
            $checks[] = ['name'=>'Agent queue', 'status'=>'ok', 'detail'=>Job::query()->where('status', 'running')->count().' running · '.Job::query()->where('status', 'queued')->count().' queued'];
        } catch (\Throwable) {
            $checks[] = ['name'=>'Database', 'status'=>'error', 'detail'=>'Unable to read database'];
        }
        $terminalRunning = is_resource($this->terminalProcess) && proc_get_status($this->terminalProcess)['running'];
        $checks[] = ['name'=>'Terminal host', 'status'=>$terminalRunning ? 'ok' : 'idle',
            'detail'=>$terminalRunning ? 'Running' : 'Stopped; starts when a terminal is opened'];
        foreach (['Runtime storage'=>dirname(__DIR__, 2).'/runtime', 'Workspace storage'=>config('crabase.workspace_root')] as $name=>$path) {
            $accessible = is_dir($path) && is_readable($path) && is_writable($path);
            $free = $accessible ? @disk_free_space($path) : false;
            $checks[] = ['name'=>$name, 'status'=>!$accessible ? 'error' : ($free === false || $free < 1073741824 ? 'warning' : 'ok'),
                'detail'=>!$accessible ? 'Folder missing or not readable/writable' : ($free === false ? 'Accessible; free space unavailable' : 'Accessible · '.round($free / 1073741824, 1).' GB free')];
        }
        return ['checked_at'=>gmdate('c'), 'checks'=>$checks];
    }

    private function status(string $value): void
    {
        Setting::put('runtime', $value);
    }
    private function send(array $message): void
    {
        $this->output .= json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
    private function rpc(string $method, array $params, callable $callback, ?int $jobId = null, ?int $relatedJobId = null): void
    {
        $id = ++$this->sequence;
        $this->pending[$id] = ['callback' => $callback, 'job_id' => $jobId, 'related_job_id' => $relatedJobId, 'method' => $method];
        $this->send(['id' => $id,'method' => $method,'params' => (object)$params]);
    }

    private function boot(): void
    {
        $this->bootAttempted = true;
        $this->status('connecting');
        $this->started = microtime(true);
        $this->process = proc_open([getenv('CODEX_BIN') ?: 'codex','app-server'], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $this->pipes, dirname(__DIR__, 3));
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Could not start Codex. Check CODEX_BIN.');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->rpc('initialize', ['clientInfo' => ['name' => 'crabase','title' => 'Crabase','version' => '0.1.0']], function ($result) {
            $this->send(['method' => 'initialized']);
            $this->ready = true;
            $this->status('connected');
            $this->loadModels();
        });
    }

    private function loadModels(?string $cursor = null, array $models = []): void
    {
        $params = ['limit' => 100,'includeHidden' => false];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $this->rpc('model/list', $params, function ($result) use ($models) {
            $models = array_merge($models, $result['data']);
            if (!empty($result['nextCursor'])) {
                $this->loadModels($result['nextCursor'], $models);
                return;
            }
            Setting::put('models', json_encode($models, JSON_INVALID_UTF8_SUBSTITUTE));
        });
    }
    private function tick(): void
    {
        try {
            $this->terminalTick();
        } catch (\Throwable $error) {
            $this->terminalFailed($error->getMessage());
        }
        try {
            if (!$this->bootAttempted && $this->clients && microtime(true) >= $this->retryAt) {
                $this->boot();
            }
            if (is_resource($this->process)) {
                if (!proc_get_status($this->process)['running']) {
                    throw new \RuntimeException('Codex stopped. Check the local Codex installation and login.');
                }
                if (!$this->ready && microtime(true) - $this->started > 30) {
                    throw new \RuntimeException('Codex initialization timed out.');
                }
                if ($this->output !== '') {
                    $written = fwrite($this->pipes[0], $this->output);
                    if ($written === false) {
                        throw new \RuntimeException('Codex connection was closed.');
                    }
                    $this->output = substr($this->output, $written);
                }
                $stderr = stream_get_contents($this->pipes[2]);
                if ($stderr) {
                    file_put_contents(dirname(__DIR__, 2).'/runtime/logs/codex.log', $stderr, FILE_APPEND);
                }
                $this->input .= stream_get_contents($this->pipes[1]);
                while (($end = strpos($this->input, "\n")) !== false) {
                    $line = substr($this->input, 0, $end);
                    $this->input = substr($this->input, $end + 1);
                    $message = json_decode($line, true);
                    if (is_array($message)) {
                        $this->receive($message);
                    }
                }
            }
            foreach (array_keys($this->jobs) as $jobId) {
                foreach ($this->jobs[$jobId]['approvals'] as $approvalId => $rpcId) {
                    $decision = Approval::query()->whereKey($approvalId)->value('decision');
                    if ($decision) {
                        $this->send(['id' => $rpcId,'result' => ['decision' => $decision]]);
                        unset($this->jobs[$jobId]['approvals'][$approvalId]);
                        if (!$this->jobs[$jobId]['approvals']) {
                            Store::notify(Chat::query()->whereKey($this->jobs[$jobId]['chat_id'])->update(['status'=>'running']));
                        }
                    }
                }
                $row = Job::query()->findOrFail($jobId, ['cancel','turn_id']);
                if ($row['cancel'] && !empty($row['turn_id']) && empty($this->jobs[$jobId]['interrupting'])) {
                    $this->jobs[$jobId]['interrupting'] = true;
                    $this->rpc('turn/interrupt', ['threadId' => $this->jobs[$jobId]['thread_id'],'turnId' => $row['turn_id']], fn ($result) => null, $jobId);
                }
            }
            $this->steerActiveJobs();
            Store::notify(Job::query()->where('status', 'queued')->where('cancel', 1)->update(['status'=>'cancelled']));
            Store::notify(Chat::query()->where('status', 'queued')
                ->whereDoesntHave('jobs', fn ($query) => $query->whereIn('status', ['queued','running']))->update(['status'=>'idle']));
            $limit = (require dirname(__DIR__, 2).'/config/crabase.php')['parallel_chats'];
            if (count($this->jobs) >= $limit) {
                return;
            }
            $nextJobs = $this->nextJobs($limit - count($this->jobs));
            if (!$nextJobs) {
                return;
            }
            if (!$this->process) {
                if (microtime(true) >= $this->retryAt) {
                    $this->boot();
                }
                return;
            }
            if (!$this->ready) {
                return;
            }
            foreach ($nextJobs as $next) {
                $this->startJob($next);
            }
        } catch (\Throwable $error) {
            foreach (array_keys($this->jobs) as $jobId) {
                $this->finish($jobId, 'failed', $error->getMessage());
            }
            $this->shutdown();
            $this->retryAt = microtime(true) + 5;
            $this->status('error');
            error_log('Crabase: '.$error->getMessage());
        }
    }

    private function nextJobs(int $limit): array
    {
        return Job::nextQueued($limit);
    }

    private function steerActiveJobs(): void
    {
        foreach (array_keys($this->jobs) as $activeId) {
            $active = &$this->jobs[$activeId];
            if (empty($active['thread_id']) || empty($active['turn_id']) || !empty($active['approvals']) || !empty($active['steering'])) {
                unset($active);
                continue;
            }
            $next = Job::query()->where('chat_id', $active['chat_id'])->where('status', 'queued')
                ->where('cancel', 0)->whereNotNull('message_id')->orderBy('id')->first()?->toArray();
            if (!$next) {
                unset($active);
                continue;
            }
            $active['steering'] = true;
            $this->rpc('turn/steer', [
                'threadId' => $active['thread_id'],
                'expectedTurnId' => $active['turn_id'],
                'input' => array_merge([
                    ['type' => 'text', 'text' => \app\service\ParticipantContext::input($next)],
                ], \app\service\Attachments::input($next)),
            ], function () use ($activeId, $next) {
                if (!isset($this->jobs[$activeId])) return;
                Store::notify(Job::query()->whereKey($next['id'])->update(['status'=>'done']));
                Store::event($next['chat_id'], Store::agentName().' received a steering message');
                $this->jobs[$activeId]['steering'] = false;
            }, $activeId, (int)$next['id']);
            unset($active);
        }
    }

    private function startJob(array $next): void
    {
        $jobId = (int)$next['id'];
        $this->jobs[$jobId] = $next + ['items' => [], 'approvals' => [], 'activity' => []];
        Store::notify(Job::query()->whereKey($jobId)->update(['status'=>'running']));
        Store::notify(Chat::query()->whereKey($next['chat_id'])->update(['status'=>'running']));
        try {
            $project = Chat::query()->find($next['chat_id'])?->project;
            if ($project?->parent_id) $next['path'] = $project->workspacePath();
            if (!$next['path']) {
                $workspaceRoot = realpath(config('crabase.workspace_root'));
                if (!$workspaceRoot || !is_dir($workspaceRoot)) throw new \RuntimeException('Workspace folder is unavailable.');
                $next['path'] = $workspaceRoot.'/.chats/'.$next['chat_id'];
                if (!is_dir($next['path']) && !mkdir($next['path'], 0700, true)) {
                    throw new \RuntimeException('Could not create chat directory.');
                }
            }
            $outputDirectory = \app\service\Artifacts::directory($next['chat_id']);
            $publisher = dirname(__DIR__, 2).'/bin/publish-artifact.php';
            $publishCommand = 'php '.escapeshellarg($publisher).' '.escapeshellarg($next['chat_id']);
            $params = ['cwd' => $next['path'],'approvalPolicy' => 'never','sandbox' => 'danger-full-access',
                'developerInstructions' => "Save user-facing deliverables in $outputDirectory. To publish any finished file, run $publishCommand ABSOLUTE_FILE_PATH (shell-quote the file path). This command registers files already in that directory or copies files from elsewhere, then returns JSON with the actual url. Always publish deliverables with this command and share the returned url verbatim using Markdown links, or image Markdown for raster images. If a skill saves elsewhere, publish that file with the same command. Do not invent download URLs or share filesystem paths. Keep normal project source edits in the project folder. Publish only requested deliverables, never secrets or credentials."];

            $params['developerInstructions'] .= Auth::coauthors($next['chat_id']);
            $params['developerInstructions'] .= \app\service\ParticipantContext::INSTRUCTIONS;
            if ($next['thread_id']) {
                $params['threadId'] = $next['thread_id'];
            }
            $this->rpc($next['thread_id'] ? 'thread/resume' : 'thread/start', $params, function ($result) use ($jobId) {
                if (!isset($this->jobs[$jobId])) {
                    return;
                }
                $thread = $result['thread']['id'];
                $this->jobs[$jobId]['thread_id'] = $thread;
                Store::notify(Chat::query()->whereKey($this->jobs[$jobId]['chat_id'])->update(['thread_id'=>$thread]));
                if (Job::query()->whereKey($jobId)->value('cancel')) {
                    $this->finish($jobId, 'cancelled');
                    return;
                }
                $job = $this->jobs[$jobId];
                $turnParams = ['threadId' => $thread,'input' => array_merge([['type' => 'text','text' => \app\service\ParticipantContext::input($job)]], \app\service\Attachments::input($job))];
                if ($job['model'] !== null) {
                    $turnParams['model'] = $job['model'];
                }
                if ($job['effort'] !== null) {
                    $turnParams['effort'] = $job['effort'];
                }
                $this->rpc('turn/start', $turnParams, function ($result) use ($jobId) {
                    if (!isset($this->jobs[$jobId])) {
                        return;
                    }
                    $this->jobs[$jobId]['turn_id'] = $result['turn']['id'];
                    Store::notify(Job::query()->whereKey($jobId)->update(['turn_id'=>$result['turn']['id']]));
                }, $jobId);
            }, $jobId);
        } catch (\Throwable $error) {
            $this->finish($jobId, 'failed', $error->getMessage());
        }
    }

    private function jobForThread(string $threadId): ?int
    {
        foreach ($this->jobs as $jobId => $job) {
            if (($job['thread_id'] ?? null) === $threadId || isset($job['activity']['agents'][$threadId])) {
                return $jobId;
            }
        }
        return null;
    }

    private function receive(array $m): void
    {
        if (isset($m['id']) && !isset($m['method'])) {
            $pending = $this->pending[$m['id']] ?? null;
            unset($this->pending[$m['id']]);
            if (!$pending) {
                return;
            }
            $jobId = $pending['job_id'];
            if ($jobId !== null && !isset($this->jobs[$jobId])) {
                return;
            }
            if (isset($m['error'])) {
                $error = $m['error']['message'] ?? 'Codex request failed.';
                if ($pending['method'] === 'turn/steer') {
                    if ($pending['related_job_id'] !== null) {
                        Store::notify(Job::query()->whereKey($pending['related_job_id'])->update(['status'=>'failed']));
                    }
                    if ($jobId !== null && isset($this->jobs[$jobId])) {
                        $this->jobs[$jobId]['steering'] = false;
                    }
                    return;
                }
                if ($jobId !== null && $pending['method'] === 'turn/interrupt') {
                    $this->item($jobId, 'interrupt-error', 'error', Store::agentName(), 'Unable to stop this turn: '.$error);
                    return;
                }
                if ($jobId !== null) {
                    $this->finish($jobId, 'failed', $error);
                    return;
                }
                throw new \RuntimeException($error);
            }
            try {
                ($pending['callback'])($m['result'] ?? []);
            } catch (\Throwable $error) {
                if ($jobId === null) {
                    throw $error;
                }
                $this->finish($jobId, 'failed', $error->getMessage());
            }
            return;
        }
        $p = $m['params'] ?? [];
        $jobId = $this->jobForThread($p['threadId'] ?? '');
        if (isset($m['id'], $m['method'])) {
            if ($jobId !== null && in_array($m['method'], ['item/commandExecution/requestApproval','item/fileChange/requestApproval'])) {
                $agentId = $m['params']['threadId'] ?? '';
                if (isset($this->jobs[$jobId]['activity']['agents'][$agentId])) {
                    $this->jobs[$jobId]['activity']['agents'][$agentId]['status'] = 'waiting';
                    $this->saveAgentActivity($jobId);
                }
                $id = Approval::query()->create(['chat_id'=>$this->jobs[$jobId]['chat_id'], 'rpc_id'=>json_encode($m['id']), 'method'=>$m['method'], 'details'=>json_encode($m['params'], JSON_INVALID_UTF8_SUBSTITUTE)])->id;
                Store::notify();
                $this->jobs[$jobId]['approvals'][$id] = $m['id'];
                Store::notify(Chat::query()->whereKey($this->jobs[$jobId]['chat_id'])->update(['status'=>'approval']));
            } else {
                $this->send(['id' => $m['id'],'error' => ['code' => -32601,'message' => 'This client does not support this interaction yet.']]);
            }
            return;
        }
        if ($jobId === null) {
            return;
        }
        if (isset($p['threadId']) && $p['threadId'] !== ($this->jobs[$jobId]['thread_id'] ?? null)) {
            $activity = \app\service\AgentActivity::child($this->jobs[$jobId]['activity'], $m['method'] ?? '', $p);
            if ($activity !== $this->jobs[$jobId]['activity']) {
                $this->jobs[$jobId]['activity'] = $activity;
                $this->saveAgentActivity($jobId);
            }
            return;
        }
        if (($m['method'] ?? '') === 'turn/started') {
            if (!empty($this->jobs[$jobId]['turn_id']) && $this->jobs[$jobId]['turn_id'] !== $p['turn']['id']) {
                return;
            }
            $this->jobs[$jobId]['turn_id'] = $p['turn']['id'];
            Store::notify(Job::query()->whereKey($jobId)->update(['turn_id'=>$p['turn']['id']]));
        }
        $eventTurn = $p['turnId'] ?? $p['turn']['id'] ?? null;
        if ($eventTurn !== null && $eventTurn !== ($this->jobs[$jobId]['turn_id'] ?? null)) {
            return;
        }
        if (($m['method'] ?? '') === 'item/agentMessage/delta') {
            $id = $this->item($jobId, $p['itemId'], 'assistant', Store::agentName(), '');
            Message::appendBody($id, $p['delta']);
        }
        if (in_array($m['method'] ?? '', ['item/started','item/completed'])) {
            $item = $p['item'];
            $type = $item['type'];
            if (in_array($type, ['collabAgentToolCall','subAgentActivity'], true)) {
                $this->jobs[$jobId]['activity'] = \app\service\AgentActivity::apply($this->jobs[$jobId]['activity'], $item);
                $this->saveAgentActivity($jobId);
            }
            if ($type === 'agentMessage' && isset($item['text'])) {
                $id = $this->item($jobId, $item['id'], 'assistant', Store::agentName(), '');
                Store::notify(Message::query()->whereKey($id)->update(['body'=>$item['text']]));
            }
            if ($type === 'commandExecution') {
                $body = ($item['command'] ?? 'Command') . "\n" . substr($item['aggregatedOutput'] ?? '', -16000);
                $id = $this->item($jobId, $item['id'], 'tool', 'Terminal', $body);
                Store::notify(Message::query()->whereKey($id)->update(['body'=>$body]));
            }
            if ($type === 'fileChange') {
                $body = implode("\n", array_map(fn ($c) => $c['path'] ?? 'File changed', $item['changes'] ?? []));
                $this->item($jobId, $item['id'], 'tool', 'File changes', $body);
            }
        }
        if (($m['method'] ?? '') === 'turn/completed') {
            $turn = $p['turn'];
            $this->finish($jobId, $turn['status'], $turn['error']['message'] ?? null);
        }
    }
    private function saveAgentActivity(int $jobId): void
    {
        if (empty($this->jobs[$jobId]['activity']['agents'])) {
            return;
        }
        $id = $this->item($jobId, 'agent-activity', 'agent_activity', Store::agentName(), '');
        Store::notify(Message::query()->whereKey($id)->update(['body'=>json_encode($this->jobs[$jobId]['activity'], JSON_INVALID_UTF8_SUBSTITUTE)]));
    }
    private function item(int $jobId, string $key, string $role, string $author, string $body): int
    {
        if (!isset($this->jobs[$jobId]['items'][$key])) {
            $this->jobs[$jobId]['items'][$key] = Message::query()->create(['chat_id'=>$this->jobs[$jobId]['chat_id'], 'role'=>$role, 'author'=>$author, 'body'=>$body, 'created_at'=>gmdate('c')])->id;
            Store::notify();
        }
        return $this->jobs[$jobId]['items'][$key];
    }
    private function finish(int $jobId, string $status, ?string $error = null): void
    {
        $chat = $this->jobs[$jobId]['chat_id'];
        if ($this->jobs[$jobId]['activity']) {
            $this->jobs[$jobId]['activity'] = \app\service\AgentActivity::finish($this->jobs[$jobId]['activity']);
            $this->saveAgentActivity($jobId);
        }
        if ($error) {
            $this->item($jobId, 'error-'.microtime(true), 'error', Store::agentName(), $error);
        }
        Store::notify(Job::query()->whereKey($jobId)->update(['status'=>$status]));
        $queued = Job::query()->where('chat_id', $chat)->where('status', 'queued')->exists();
        Store::notify(Chat::query()->whereKey($chat)->update(['status'=>$queued ? 'queued' : 'idle', 'updated_at'=>gmdate('c')]));
        Store::notify(Approval::query()->where('chat_id', $chat)->whereNull('decision')->update(['decision'=>'decline']));
        Store::event($chat, Store::agentName().' turn '.$status);
        foreach ($this->jobs[$jobId]['approvals'] as $rpcId) {
            $this->send(['id' => $rpcId,'result' => ['decision' => 'decline']]);
        }
        unset($this->jobs[$jobId]);
        foreach ($this->pending as $id => $pending) {
            if ($pending['job_id'] === $jobId) {
                unset($this->pending[$id]);
            }
        }
    }
    private function shutdown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = [];
        $this->pending = [];
        $this->ready = false;
        $this->output = '';
        $this->input = '';
    }
    public function onWorkerStop(): void
    {
        foreach (array_keys($this->jobs) as $jobId) {
            $this->finish($jobId, 'interrupted', 'The workspace server stopped. Send a new message to resume.');
        }
        $this->shutdown();
        $this->terminalShutdown();
        $this->status('offline');
    }

    private function terminalAction(TcpConnection $connection, string $action, array $data): array
    {
        $chatId = $this->clients[$connection->id]['chat_id'];
        if (!$chatId) {
            throw new \InvalidArgumentException('Open a chat before using the terminal.');
        }
        $actor = Auth::user($this->clients[$connection->id]['token']);
        if (!$actor) throw new \InvalidArgumentException('Authentication required.');
        ProjectAccess::chat($actor, $chatId);
        if ($action === 'terminalOpen') {
            $row = Chat::query()->with('project')->find($chatId);
            if (!$row) {
                throw new \InvalidArgumentException('Conversation not found.');
            }
            $path = $row->project?->workspacePath();
            if (!$path) {
                $workspaceRoot = realpath(config('crabase.workspace_root'));
                if (!$workspaceRoot || !is_dir($workspaceRoot)) throw new \RuntimeException('Workspace folder is unavailable.');
                $path = $workspaceRoot.'/.chats/'.$chatId;
            }
            $cwd = $path;
            if (!$path && !is_dir($cwd) && !mkdir($cwd, 0700, true)) {
                throw new \RuntimeException('Could not create the chat directory.');
            }
            $cwd = realpath($cwd);
            if (!$cwd || !is_dir($cwd) || !is_readable($cwd)) {
                throw new \InvalidArgumentException('The project folder is unavailable.');
            }
            [$cols, $rows] = $this->terminalSize($data);
            $id = bin2hex(random_bytes(8));
            $this->terminals[$id] = ['id' => $id,'chat_id' => $chatId,'title' => 'Terminal '.(++$this->terminalSequence),'output' => '','running' => true];
            try {
                $this->terminalSend(['action' => 'open','id' => $id,'cwd' => $cwd,'cols' => $cols,'rows' => $rows]);
            } catch (\Throwable $error) {
                unset($this->terminals[$id]);
                throw $error;
            }
            $this->terminalBroadcast($chatId, ['event' => 'opened','terminal' => $this->terminals[$id]]);
            return ['terminal' => $this->terminals[$id]];
        }
        $id = Store::text($data['terminal_id'] ?? null, 64);
        $terminal = $this->terminals[$id] ?? null;
        if (!$terminal || $terminal['chat_id'] !== $chatId) {
            throw new \InvalidArgumentException('Terminal not found.');
        }
        if (!$terminal['running'] && $action !== 'terminalClose') {
            throw new \InvalidArgumentException('This terminal has exited.');
        }
        if ($action === 'terminalInput') {
            $input = $data['input'] ?? null;
            if (!is_string($input) || $input === '' || strlen($input) > 16384) {
                throw new \InvalidArgumentException('Terminal input must be between 1 and 16384 bytes.');
            }
            $this->terminalSend(['action' => 'input','id' => $id,'data' => $input]);
        } elseif ($action === 'terminalResize') {
            [$cols, $rows] = $this->terminalSize($data);
            $this->terminalSend(['action' => 'resize','id' => $id,'cols' => $cols,'rows' => $rows]);
        } elseif ($action === 'terminalClose') {
            $this->terminalSend(['action' => 'close','id' => $id]);
            unset($this->terminals[$id]);
            $this->terminalBroadcast($chatId, ['event' => 'closed','terminal_id' => $id]);
        } else {
            throw new \InvalidArgumentException('Unknown terminal action.');
        }
        return ['ok' => true];
    }

    private function terminalSize(array $data): array
    {
        $cols = $data['cols'] ?? 80;
        $rows = $data['rows'] ?? 24;
        if (!is_int($cols) || !is_int($rows) || $cols < 20 || $cols > 500 || $rows < 2 || $rows > 200) {
            throw new \InvalidArgumentException('Invalid terminal size.');
        }
        return [$cols, $rows];
    }

    private function terminalList(?string $chatId): array
    {
        return array_values(array_map(
            fn ($terminal) => array_merge($terminal, ['output' => substr($terminal['output'], -200000)]),
            array_filter($this->terminals, fn ($terminal) => $terminal['chat_id'] === $chatId),
        ));
    }

    private function terminalSend(array $message): void
    {
        if (!$this->terminalProcess) {
            $root = dirname(__DIR__, 3);
            $this->terminalProcess = proc_open([getenv('NODE_BIN') ?: 'node',$root.'/server/bin/terminal-host.mjs'], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $this->terminalPipes, $root);
            if (!is_resource($this->terminalProcess)) {
                $this->terminalProcess = null;
                throw new \RuntimeException('Could not start the terminal host.');
            }
            foreach ($this->terminalPipes as $pipe) stream_set_blocking($pipe, false);
        }
        $this->terminalOutput .= json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    private function terminalTick(): void
    {
        if (!is_resource($this->terminalProcess)) return;
        if (!proc_get_status($this->terminalProcess)['running']) {
            throw new \RuntimeException('Terminal host stopped.');
        }
        if ($this->terminalOutput !== '') {
            $written = fwrite($this->terminalPipes[0], $this->terminalOutput);
            if ($written === false) throw new \RuntimeException('Terminal host connection closed.');
            $this->terminalOutput = substr($this->terminalOutput, $written);
        }
        $stderr = stream_get_contents($this->terminalPipes[2]);
        if ($stderr) file_put_contents(dirname(__DIR__, 2).'/runtime/logs/terminal.log', $stderr, FILE_APPEND);
        $this->terminalInput .= stream_get_contents($this->terminalPipes[1]);
        while (($end = strpos($this->terminalInput, "\n")) !== false) {
            $line = substr($this->terminalInput, 0, $end);
            $this->terminalInput = substr($this->terminalInput, $end + 1);
            $message = json_decode($line, true);
            $id = $message['id'] ?? null;
            if (!is_array($message) || !$id || !isset($this->terminals[$id])) continue;
            $chatId = $this->terminals[$id]['chat_id'];
            if ($message['event'] === 'output' && is_string($message['data'] ?? null)) {
                $this->terminals[$id]['output'] .= $message['data'];
                if (strlen($this->terminals[$id]['output']) > 1000000) {
                    $this->terminals[$id]['output'] = substr($this->terminals[$id]['output'], -1000000);
                }
                $this->terminalBroadcast($chatId, ['event' => 'output','terminal_id' => $id,'data' => $message['data']]);
            } elseif ($message['event'] === 'exit') {
                unset($this->terminals[$id]);
                $this->terminalBroadcast($chatId, ['event' => 'closed','terminal_id' => $id]);
            } elseif ($message['event'] === 'error') {
                $this->terminals[$id]['running'] = false;
                $this->terminalBroadcast($chatId, ['event' => 'error','terminal_id' => $id,'message' => $message['message'] ?? 'Terminal failed.']);
            }
        }
    }

    private function terminalBroadcast(string $chatId, array $packet): void
    {
        $packet += ['type' => 'terminal','chat_id' => $chatId];
        foreach ($this->clients as $id => $client) {
            if ($client['chat_id'] === $chatId && isset($this->worker->connections[$id]) && ($actor = Auth::user($client['token'])) && ProjectAccess::canChat($actor, $chatId)) {
                $this->reply($this->worker->connections[$id], $packet);
            }
        }
    }

    private function terminalFailed(string $message): void
    {
        foreach ($this->terminals as &$terminal) {
            $terminal['running'] = false;
            $this->terminalBroadcast($terminal['chat_id'], ['event' => 'error','terminal_id' => $terminal['id'],'message' => $message]);
        }
        unset($terminal);
        $this->terminalShutdown();
    }

    private function terminalShutdown(): void
    {
        foreach ($this->terminalPipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($this->terminalProcess)) {
            proc_terminate($this->terminalProcess);
            proc_close($this->terminalProcess);
        }
        $this->terminalProcess = null;
        $this->terminalPipes = [];
        $this->terminalInput = '';
        $this->terminalOutput = '';
    }
}
