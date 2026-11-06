<?php

namespace App\Process;

use App\Service\{Actions, AgentActivity, Artifacts, Attachments, Auth, Input, MessageBody, ModelCatalog, ParticipantContext, ProjectAccess, ProjectWorkspace, Store, TokenUsage, Updater};
use Crab\Contracts\Log\Logger;
use Crab\Engine\Http\Request;
use Crab\Engine\Process\Worker;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Http\Request as HttpRequest;
use Crab\Log\Logger as DefaultLogger;

/**
 * The persistent Codex worker: owns the single `codex app-server` process and the shared agent queue,
 * and routes browser WebSocket requests. Clients, terminals and admin child processes live in their own classes.
 */
final class Codex
{
    private readonly Logger $logger;
    private readonly StreamWatchers $watchers;
    private readonly WorkspaceClients $clients;
    private readonly TerminalHost $terminals;
    /** Actions that run Git; they execute in a child process so a slow repository never stalls streaming. */
    private const GIT_ACTIONS = ['projectContext', 'projectWorkspace', 'projectDiff', 'worktreeCreate'];
    private ?\Crab\Engine\EventLoop $loop = null;
    private ?AdminTasks $admin = null;
    private mixed $process = null;
    private array $pipes = [];
    private array $pending = [];
    private array $jobs = [];
    private string $input = '';
    private string $output = '';
    private int $sequence = 0;
    private bool $ready = false;
    private float $retryAt = 0;
    private float $started = 0;
    private bool $bootAttempted = false;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new DefaultLogger();
        $this->watchers = new StreamWatchers();
        $this->clients = new WorkspaceClients();
        $this->terminals = new TerminalHost($this->watchers, $this->clients->toChat(...));
    }

    public function onWorkerStart(Worker $worker): void
    {
        $loop = $this->loop = $worker->loop;
        $this->watchers->attach($loop);
        $this->admin = new AdminTasks($loop, $this->watchers, $this->clients, $this->logger);
        // runtime/ is not in Git; codex.log and terminal.log appends crash the worker without this folder.
        $logs = dirname(__DIR__, 2).'/runtime/logs';
        if (!is_dir($logs) && !mkdir($logs, 0770, true) && !is_dir($logs)) throw new \RuntimeException('Could not create '.$logs);
        // Nothing survives a restart: fail running work, release approvals and close open sub-agent activity.
        Store::transaction(function ($db): void {
            $affected = $db->table('jobs')->where('status', 'running')->update(['status'=>'failed'])
                + $db->table('chats')->whereIn('status', ['running','approval'])->update(['status'=>'idle'])
                + $db->table('approvals')->whereNull('decision')->update(['decision'=>'decline']);
            foreach ($db->table('messages')->where('role', 'agent_activity')->select('id','body')->get() as $row) {
                $activity = json_decode($row['body'], true);
                if (!empty($activity['active'])) {
                    $affected += $db->table('messages')->where('id', $row['id'])->update(['body'=>json_encode(AgentActivity::finish($activity), JSON_INVALID_UTF8_SUBSTITUTE)]);
                }
            }
            Store::notify($affected);
        });
        $this->status('ready');
        Attachments::cleanup();
        $loop->repeat(3600, fn () => Attachments::cleanup());
        // One persistent app-server, with independent turns for different chats.
        $loop->repeat(0.05, function () {
            $this->tick();
            $this->clients->publish();
        });
        $loop->repeat(2, function () {
            $this->clients->sweep();
            $this->admin?->pushUpdateStatus();
            $this->admin?->publishTokenUsage();
        });
    }

    public function onWebSocketConnect(WebSocketSession $connection, Request $request): void
    {
        $host = explode(':', (string) ($request->header('host') ?? ''))[0];
        if (!Auth::allowedHost($host) || !Auth::allowedOrigin($request->header('origin'))) {
            $connection->close();
            return;
        }
        $token = HttpRequest::fromEngine($request)->cookie(Auth::COOKIE);
        if (!Auth::user($token)) {
            $this->clients->reject($connection);
            return;
        }
        $this->clients->add($connection, $token);
    }

    public function onClose(WebSocketSession $connection): void
    {
        $this->clients->remove($connection);
    }

    public function onMessage(WebSocketSession $connection, string $data): void
    {
        if (!$this->clients->has($connection)) {
            $connection->close();
            return;
        }
        $id = null;
        try {
            $actor = $this->clients->actor($connection);
            if (!$actor) {
                $this->clients->reject($connection);
                return;
            }
            if (strlen($data) > 100000) throw new \InvalidArgumentException('Request too large.');
            $m = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($m) || !is_int($m['id'] ?? null) || !is_string($m['action'] ?? null) || !is_array($m['data'] ?? null)) {
                throw new \InvalidArgumentException('Expected id, action, and data.');
            }
            $id = $m['id'];
            $action = $m['action'];
            $presence = [];
            if (!empty($m['data']['project_id'])) ProjectAccess::project($actor, Input::text($m['data']['project_id'], 64));
            if (!empty($m['data']['chat_id'])) ProjectAccess::chat($actor, Input::text($m['data']['chat_id'], 64));
            if ($action === 'updateCheck') {
                if (!$actor['admin']) throw new \InvalidArgumentException('Administrator access required.');
                ($this->admin ?? throw new \RuntimeException('Worker is not started.'))->updateCheck($connection, $id);
                return;
            }
            if ($this->loop && in_array($action, self::GIT_ACTIONS, true)) {
                $this->runInChild($connection, $id, $actor, $action, $this->guardProjectDelete($action, $m['data']));
                return;
            }
            $result = match (true) {
                $action === 'models' => $this->refreshModels(),
                $action === 'sync' => $this->sync($connection, $actor, $m['data'], $presence),
                $action === 'typing' => $this->typing($connection, $m['data'], $presence),
                str_starts_with($action, 'terminal') => $this->terminalAction($connection, $actor, $action, $m['data']),
                // Actions that need worker state; everything else is authorized and routed by Actions.
                $action === 'health' => $this->requireAdmin($actor) ?? $this->health(),
                $action === 'updateStart' => $this->requireAdmin($actor) ?? Updater::start([
                    'active_jobs' => Store::connection()->table('jobs')->whereIn('status', ['queued', 'running'])->count(),
                    'active_terminals' => $this->terminals->runningCount(),
                ]),
                default => Actions::request($actor, $action, $this->guardProjectDelete($action, $m['data'])),
            };
            $this->clients->reply($connection, ['id' => $id,'result' => $result]);
            foreach ($presence as $chatId) $this->clients->presence($chatId);
            $this->clients->publish();
        } catch (\InvalidArgumentException|\JsonException $e) {
            $this->clients->reply($connection, ['id' => $id,'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('Codex request failed.', ['exception' => $e]);
            $this->clients->reply($connection, ['id' => $id,'error' => 'Unable to save changes. Please try again.']);
        }
    }

    /** Replies when bin/action.php finishes; its writes bump the revision, so the next tick publishes them. */
    private function runInChild(WebSocketSession $connection, int $id, array $actor, string $action, array $data): void
    {
        $request = json_encode(['actor' => $actor, 'action' => $action, 'data' => $data], JSON_THROW_ON_ERROR);
        ChildProcess::run($this->loop, $this->watchers, [PHP_BINARY, dirname(__DIR__, 2).'/bin/action.php', $request], 60, 16000000, function (string $output, ?string $failure) use ($connection, $id, $action): void {
            $value = $failure === null ? json_decode($output, true) : null;
            if (!is_array($value) || !(array_key_exists('result', $value) || is_string($value['error'] ?? null))) {
                $this->logger->error('Workspace action failed.', ['action' => $action, 'failure' => $failure]);
                $value = ['error' => $failure === 'timeout' ? 'Git took too long. Check the repository and try again.' : 'Unable to save changes. Please try again.'];
            }
            if ($this->clients->isOpen($connection)) $this->clients->reply($connection, ['id' => $id] + $value);
        }, dirname(__DIR__, 2).'/runtime/logs/action.log');
    }

    private function requireAdmin(array $actor): null
    {
        if (!$actor['admin']) throw new \InvalidArgumentException('Administrator access required.');
        return null;
    }

    private function refreshModels(): array
    {
        if (!$this->process) $this->boot();
        elseif ($this->ready) $this->loadModels(true);
        return ['ok' => true];
    }

    /** @param list<?string> $presence chats whose presence must be rebroadcast after the reply */
    private function sync(WebSocketSession $connection, array $actor, array $data, array &$presence): array
    {
        $chatId = empty($data['chat_id']) ? null : Input::text($data['chat_id'], 64);
        $after = $data['after'] ?? null;
        $before = $data['before'] ?? null;
        $limit = $data['limit'] ?? 150;
        if ($after !== null && (!is_int($after) || $after < 0)) throw new \InvalidArgumentException('Invalid sync cursor.');
        if ($before !== null && (!is_int($before) || $before < 0)) throw new \InvalidArgumentException('Invalid history cursor.');
        if (!is_int($limit) || $limit < 1 || $limit > 500) throw new \InvalidArgumentException('Invalid message limit.');
        $thread = $chatId ? Store::threadPage($chatId, $before, $after, $limit) : null;
        $state = WorkspaceClients::state($actor);
        $presence = $this->clients->subscribe($connection, $actor, $chatId, $state, $thread, $before !== null);
        return ['state' => $state,'thread' => $thread,'terminals' => $this->terminals->list($chatId)];
    }

    private function typing(WebSocketSession $connection, array $data, array &$presence): array
    {
        $chatId = $this->clients->chatId($connection);
        if (!is_bool($data['active'] ?? null)) throw new \InvalidArgumentException('Active must be a boolean.');
        if (!$chatId || ($data['chat_id'] ?? null) !== $chatId) throw new \InvalidArgumentException('Open this chat before typing in it.');
        if ($this->clients->typing($connection, $data['active'])) $presence = [$chatId];
        return ['ok' => true];
    }

    private function terminalAction(WebSocketSession $connection, array $actor, string $action, array $data): array
    {
        $chatId = $this->clients->chatId($connection) ?? throw new \InvalidArgumentException('Open a chat before using the terminal.');
        ProjectAccess::chat($actor, $chatId);
        return $this->terminals->action($chatId, $action, $data);
    }

    /** Terminals are worker state, so this precondition is checked here before Actions deletes the project. */
    private function guardProjectDelete(string $action, array $data): array
    {
        if ($action === 'projectDelete') {
            $chatIds = Store::connection()->table('chats')->where('project_id', Input::text($data['project_id'] ?? null, 64))->pluck('id');
            if ($this->terminals->runningIn($chatIds)) throw new \InvalidArgumentException('Close this workspace’s terminals before deleting it.');
        }
        return $data;
    }

    private function health(): array
    {
        $checks = [['name'=>'WebSocket worker', 'status'=>'ok', 'detail'=>'Responding']];
        $running = is_resource($this->process) && proc_get_status($this->process)['running'];
        $checks[] = ['name'=>'Codex app-server', 'status'=>$running && $this->ready ? 'ok' : 'warning',
            'detail'=>$running ? ($this->ready ? 'Initialized and running' : 'Starting; not ready yet') : ($this->bootAttempted ? 'Not running' : 'Not started yet')];
        try {
            $db = Store::connection();
            $models = ModelCatalog::all();
            $checks[] = ['name'=>'Database', 'status'=>'ok', 'detail'=>'Read query succeeded'];
            $checks[] = ['name'=>'Models', 'status'=>$models ? 'ok' : 'warning', 'detail'=>count($models).' models in memory; refreshes every 30 minutes'];
            $checks[] = ['name'=>'Agent queue', 'status'=>'ok', 'detail'=>$db->table('jobs')->where('status', 'running')->count().' running · '.$db->table('jobs')->where('status', 'queued')->count().' queued'];
        } catch (\Throwable) {
            $checks[] = ['name'=>'Database', 'status'=>'error', 'detail'=>'Unable to read database'];
        }
        $terminalRunning = $this->terminals->hostRunning();
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
        Store::notify(Store::connection()->table('settings')->upsert([['key' => 'runtime', 'value' => $value]], ['key'], ['value']));
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

    private function loadModels(bool $force = false, ?string $cursor = null, array $models = []): void
    {
        if ($cursor === null && !$force && !ModelCatalog::expired()) return;
        if ($cursor === null && !ModelCatalog::beginRefresh()) return;
        $params = ['limit' => 100,'includeHidden' => false];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $this->rpc('model/list', $params, function ($result) use ($models) {
            $models = array_merge($models, $result['data']);
            if (!empty($result['nextCursor'])) {
                $this->loadModels(false, $result['nextCursor'], $models);
                return;
            }
            ModelCatalog::replace($models);
            ModelCatalog::finishRefresh();
            Store::notify();
        });
    }
    private function tick(): void
    {
        $this->terminals->check();
        try {
            if (!$this->bootAttempted && $this->clients->any() && microtime(true) >= $this->retryAt) {
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
            if ($this->ready && ModelCatalog::expired()) {
                $this->loadModels();
            }
            foreach (array_keys($this->jobs) as $jobId) {
                foreach ($this->jobs[$jobId]['approvals'] as $approvalId => $rpcId) {
                    $decision = Store::connection()->table('approvals')->where('id', $approvalId)->value('decision');
                    if ($decision) {
                        $this->send(['id' => $rpcId,'result' => ['decision' => $decision]]);
                        unset($this->jobs[$jobId]['approvals'][$approvalId]);
                        if (!$this->jobs[$jobId]['approvals']) {
                            Store::notify(Store::connection()->table('chats')->where('id', $this->jobs[$jobId]['chat_id'])->update(['status'=>'running']));
                        }
                    }
                }
                $row = Store::connection()->table('jobs')->where('id', $jobId)->select('cancel','turn_id')->first();
                if (!$row) throw new \RuntimeException('Queued job disappeared.');
                if ($row['cancel'] && !empty($row['turn_id']) && empty($this->jobs[$jobId]['interrupting'])) {
                    $this->jobs[$jobId]['interrupting'] = true;
                    $this->rpc('turn/interrupt', ['threadId' => $this->jobs[$jobId]['thread_id'],'turnId' => $row['turn_id']], fn ($result) => null, $jobId);
                }
            }
            $this->steerActiveJobs();
            Store::notify(Store::connection()->table('jobs')->where('status', 'queued')->where('cancel', 1)->update(['status'=>'cancelled']));
            $active = Store::connection()->table('jobs')->selectRaw('1')
                ->whereColumn('jobs.chat_id', 'chats.id')->whereIn('jobs.status', ['queued', 'running']);
            Store::notify(Store::connection()->table('chats')->where('status', 'queued')
                ->whereNotExists($active)->update(['status'=>'idle']));
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
            ModelCatalog::finishRefresh();
            foreach (array_keys($this->jobs) as $jobId) {
                $this->finish($jobId, 'failed', $error->getMessage());
            }
            $this->shutdown();
            $this->retryAt = microtime(true) + 5;
            $this->status('error');
            $this->logger->error('Crabase worker failed.', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }

    private function nextJobs(int $limit): array
    {
        $db = Store::connection();
        $active = $db->table('jobs as active')->selectRaw('1')->whereColumn('active.chat_id', 'j.chat_id')->where('active.status', 'running');
        $earlier = $db->table('jobs as earlier')->selectRaw('1')->whereColumn('earlier.chat_id', 'j.chat_id')->where('earlier.status', 'queued')->where('earlier.cancel', 0)->whereColumn('earlier.id', '<', 'j.id');
        return $db->table('jobs as j')->select('j.*', 'c.thread_id', 'p.path')
            ->join('chats as c', 'c.id', '=', 'j.chat_id')->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->where('j.status', 'queued')->where('j.cancel', 0)->whereNotExists($active)->whereNotExists($earlier)
            ->orderBy('j.id')->limit($limit)->get();
    }

    private function steerActiveJobs(): void
    {
        foreach (array_keys($this->jobs) as $activeId) {
            $active = &$this->jobs[$activeId];
            if (empty($active['thread_id']) || empty($active['turn_id']) || !empty($active['approvals']) || !empty($active['steering'])) {
                unset($active);
                continue;
            }
            $next = Store::connection()->table('jobs')->where('chat_id', $active['chat_id'])->where('status', 'queued')
                ->where('cancel', 0)->whereNotNull('message_id')->orderBy('id')->first();
            if (!$next) {
                unset($active);
                continue;
            }
            $active['steering'] = true;
            $this->rpc('turn/steer', [
                'threadId' => $active['thread_id'],
                'expectedTurnId' => $active['turn_id'],
                'input' => array_merge([
                    ['type' => 'text', 'text' => ParticipantContext::input($next)],
                ], Attachments::input($next)),
            ], function () use ($activeId, $next) {
                if (!isset($this->jobs[$activeId])) return;
                $this->jobs[$activeId]['requester_message_id'] = $next['message_id'];
                Store::notify(Store::connection()->table('jobs')->where('id', $next['id'])->update(['status'=>'done']));
                Store::event($next['chat_id'], Store::agentName().' received a steering message');
                $this->jobs[$activeId]['steering'] = false;
            }, $activeId, (int)$next['id']);
            unset($active);
        }
    }

    private function startJob(array $next): void
    {
        $jobId = (int)$next['id'];
        // Token usage is credited to the person whose message drives the turn; a steer hands it to the steering person.
        $this->jobs[$jobId] = $next + ['items' => [], 'approvals' => [], 'activity' => [], 'requester_message_id' => $next['message_id'] ?? null, 'resumed_thread_id' => $next['thread_id'] ?? null];
        Store::transaction(function ($db) use ($jobId, $next): void {
            Store::notify($db->table('jobs')->where('id', $jobId)->update(['status'=>'running'])
                + $db->table('chats')->where('id', $next['chat_id'])->update(['status'=>'running']));
        });
        try {
            $next['path'] = ProjectWorkspace::chatPath($next['chat_id']);
            $outputDirectory = Artifacts::directory($next['chat_id']);
            $publisher = dirname(__DIR__, 2).'/bin/publish-artifact.php';
            $publishCommand = 'php '.escapeshellarg($publisher).' '.escapeshellarg($next['chat_id']);
            $params = ['cwd' => $next['path'],'approvalPolicy' => 'never','sandbox' => 'danger-full-access',
                'developerInstructions' => "Save user-facing deliverables in $outputDirectory. To publish any finished file, run $publishCommand ABSOLUTE_FILE_PATH (shell-quote the file path). This command registers files already in that directory or copies files from elsewhere, then returns JSON with the actual url. Always publish deliverables with this command and share the returned url verbatim using Markdown links, or image Markdown for raster images. If a skill saves elsewhere, publish that file with the same command. Do not invent download URLs or share filesystem paths. Keep normal project source edits in the project folder. Publish only requested deliverables, never secrets or credentials."];

            $params['developerInstructions'] .= Auth::coauthors($next['chat_id']);
            $params['developerInstructions'] .= ParticipantContext::INSTRUCTIONS;
            if ($next['thread_id']) {
                $params['threadId'] = $next['thread_id'];
            }
            $this->rpc($next['thread_id'] ? 'thread/resume' : 'thread/start', $params, function ($result) use ($jobId) {
                if (!isset($this->jobs[$jobId])) {
                    return;
                }
                $thread = $result['thread']['id'];
                $this->jobs[$jobId]['thread_id'] = $thread;
                Store::notify(Store::connection()->table('chats')->where('id', $this->jobs[$jobId]['chat_id'])->update(['thread_id'=>$thread]));
                if (Store::connection()->table('jobs')->where('id', $jobId)->value('cancel')) {
                    $this->finish($jobId, 'cancelled');
                    return;
                }
                $job = $this->jobs[$jobId];
                $turnParams = ['threadId' => $thread,'input' => array_merge([['type' => 'text','text' => ParticipantContext::input($job)]], Attachments::input($job))];
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
                    Store::notify(Store::connection()->table('jobs')->where('id', $jobId)->update(['turn_id'=>$result['turn']['id']]));
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
                        Store::notify(Store::connection()->table('jobs')->where('id', $pending['related_job_id'])->update(['status'=>'failed']));
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
                $chatId = $this->jobs[$jobId]['chat_id'];
                $id = Store::transaction(function ($db) use ($chatId, $m): int {
                    $id = $db->table('approvals')->insert(['chat_id'=>$chatId, 'rpc_id'=>json_encode($m['id']), 'method'=>$m['method'], 'details'=>json_encode($m['params'], JSON_INVALID_UTF8_SUBSTITUTE)]);
                    $db->table('chats')->where('id', $chatId)->update(['status'=>'approval']);
                    Store::notify();
                    return $id;
                });
                $this->jobs[$jobId]['approvals'][$id] = $m['id'];
            } else {
                $this->send(['id' => $m['id'],'error' => ['code' => -32601,'message' => 'This client does not support this interaction yet.']]);
            }
            return;
        }
        if ($jobId === null) {
            return;
        }
        if (($m['method'] ?? '') === 'thread/tokenUsage/updated') {
            // Sub-agent threads map to their parent job, so their usage goes to the same person.
            $job = $this->jobs[$jobId];
            $requester = $job['requester_message_id'] ?? $job['message_id'] ?? null;
            $thread = (string) ($p['threadId'] ?? '');
            // Only the thread this job resumed has history before this job; new chats and sub-agent threads start at zero.
            $fresh = $thread !== '' && $thread !== ($job['resumed_thread_id'] ?? null);
            TokenUsage::record($requester === null ? null : (int) $requester, $thread, $fresh, (array) ($p['tokenUsage'] ?? []));
            return;
        }
        if (isset($p['threadId']) && $p['threadId'] !== ($this->jobs[$jobId]['thread_id'] ?? null)) {
            $activity = AgentActivity::child($this->jobs[$jobId]['activity'], $m['method'] ?? '', $p);
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
            Store::notify(Store::connection()->table('jobs')->where('id', $jobId)->update(['turn_id'=>$p['turn']['id']]));
        }
        $eventTurn = $p['turnId'] ?? $p['turn']['id'] ?? null;
        if ($eventTurn !== null && $eventTurn !== ($this->jobs[$jobId]['turn_id'] ?? null)) {
            return;
        }
        if (($m['method'] ?? '') === 'item/agentMessage/delta') {
            $id = $this->item($jobId, $p['itemId'], 'assistant', Store::agentName(), '');
            MessageBody::append($id, $p['delta']);
        }
        if (in_array($m['method'] ?? '', ['item/started','item/completed'])) {
            $item = $p['item'];
            $type = $item['type'];
            if (in_array($type, ['collabAgentToolCall','subAgentActivity'], true)) {
                $this->jobs[$jobId]['activity'] = AgentActivity::apply($this->jobs[$jobId]['activity'], $item);
                $this->saveAgentActivity($jobId);
            }
            if ($type === 'agentMessage' && isset($item['text'])) {
                $id = $this->item($jobId, $item['id'], 'assistant', Store::agentName(), '');
                Store::notify(Store::connection()->table('messages')->where('id', $id)->update(['body'=>$item['text']]));
            }
            if ($type === 'commandExecution') {
                $body = ($item['command'] ?? 'Command') . "\n" . substr($item['aggregatedOutput'] ?? '', -16000);
                $id = $this->item($jobId, $item['id'], 'tool', 'Terminal', $body);
                Store::notify(Store::connection()->table('messages')->where('id', $id)->update(['body'=>$body]));
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
        Store::notify(Store::connection()->table('messages')->where('id', $id)->update(['body'=>json_encode($this->jobs[$jobId]['activity'], JSON_INVALID_UTF8_SUBSTITUTE)]));
    }
    private function item(int $jobId, string $key, string $role, string $author, string $body): int
    {
        if (!isset($this->jobs[$jobId]['items'][$key])) {
            $this->jobs[$jobId]['items'][$key] = Store::connection()->table('messages')->insert(['chat_id'=>$this->jobs[$jobId]['chat_id'], 'role'=>$role, 'author'=>$author, 'body'=>$body, 'created_at'=>gmdate('c')]);
            Store::notify();
        }
        return $this->jobs[$jobId]['items'][$key];
    }
    private function finish(int $jobId, string $status, ?string $error = null): void
    {
        $chat = $this->jobs[$jobId]['chat_id'];
        Store::transaction(function ($db) use ($jobId, $chat, $status, $error): void {
            if ($this->jobs[$jobId]['activity']) {
                $this->jobs[$jobId]['activity'] = AgentActivity::finish($this->jobs[$jobId]['activity']);
                $this->saveAgentActivity($jobId);
            }
            if ($error) $this->item($jobId, 'error-'.microtime(true), 'error', Store::agentName(), $error);
            $db->table('jobs')->where('id', $jobId)->update(['status'=>$status]);
            $queued = $db->table('jobs')->where('chat_id', $chat)->where('status', 'queued')->exists();
            $db->table('chats')->where('id', $chat)->update(['status'=>$queued ? 'queued' : 'idle', 'updated_at'=>gmdate('c')]);
            $db->table('approvals')->where('chat_id', $chat)->whereNull('decision')->update(['decision'=>'decline']);
            Store::event($chat, Store::agentName().' turn '.$status);
        });
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
        $this->terminals->shutdown();
        $this->watchers->cancelAll();
        $this->loop = null;
        $this->admin = null;
        $this->status('offline');
    }
}
