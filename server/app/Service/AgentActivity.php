<?php
namespace App\Service;

final class AgentActivity
{
    public static function apply(array $state, array $item): array
    {
        $state += ['active'=>true, 'agents'=>[]];
        if ($item['type'] === 'subAgentActivity') {
            $id = $item['agentThreadId'];
            $agent = $state['agents'][$id] ?? ['id'=>$id, 'task'=>'', 'message'=>'', 'status'=>'pendingInit'];
            $agent['name'] = basename($item['agentPath']);
            $agent['status'] = match ($item['kind']) {
                'started', 'interacted' => 'running',
                'completed' => $agent['status'] === 'errored' ? 'errored' : 'completed',
                'interrupted' => 'interrupted',
                default => $agent['status'],
            };
            $state['agents'][$id] = $agent;
            return $state;
        }
        $ids = array_unique(array_merge($item['receiverThreadIds'] ?? [], array_keys($item['agentsStates'] ?? [])));
        $placeholder = 'spawn:'.$item['id'];
        if ($item['tool'] === 'spawnAgent') {
            if ($ids) unset($state['agents'][$placeholder]);
            else $ids = [$placeholder];
        }
        foreach ($ids as $id) {
            $agent = $state['agents'][$id] ?? ['id'=>$id, 'name'=>'', 'task'=>'', 'message'=>'', 'status'=>'pendingInit'];
            if (in_array($item['tool'], ['spawnAgent','followupTask'], true) && !empty($item['prompt'])) {
                $agent['task'] = substr($item['prompt'], 0, 20000);
            }
            $reported = $item['agentsStates'][$id] ?? null;
            if ($reported) {
                // Closing a completed agent should not erase its successful result.
                if ($reported['status'] !== 'shutdown' || $agent['status'] !== 'completed') $agent['status'] = $reported['status'];
                if (!empty($reported['message'])) $agent['message'] = substr($reported['message'], 0, 20000);
            } elseif ($item['status'] === 'failed' && $item['tool'] === 'spawnAgent') {
                $agent['status'] = 'errored';
            }
            $state['agents'][$id] = $agent;
        }
        return $state;
    }

    public static function child(array $state, string $method, array $params): array
    {
        $id = $params['threadId'];
        if (!isset($state['agents'][$id])) return $state;
        $agent = &$state['agents'][$id];
        if ($method === 'turn/started') $agent['status'] = 'running';
        if ($method === 'turn/completed') {
            $agent['status'] = match ($params['turn']['status']) {
                'completed' => 'completed', 'failed' => 'errored', default => 'interrupted',
            };
            if (!empty($params['turn']['error']['message'])) $agent['message'] = substr($params['turn']['error']['message'], 0, 20000);
        }
        if ($method === 'item/agentMessage/delta') $agent['message'] = substr(($agent['message'] ?? '').$params['delta'], -20000);
        if (in_array($method, ['item/started','item/completed'], true)) {
            $item = $params['item'];
            if ($item['type'] === 'agentMessage') $agent['message'] = substr($item['text'] ?? '', 0, 20000);
            if ($item['type'] === 'commandExecution') {
                $agent['logs'][$item['id']] = substr(($item['command'] ?? '')."\n".($item['aggregatedOutput'] ?? ''), -16000);
                // ponytail: retain eight recent tool outputs per agent; use separate storage if full logs are needed.
                $agent['logs'] = array_slice($agent['logs'], -8, null, true);
            }
        }
        return $state;
    }

    public static function finish(array $state): array
    {
        $state['active'] = false;
        foreach ($state['agents'] as &$agent) {
            if (in_array($agent['status'], ['running','pendingInit','waiting'], true)) $agent['status'] = 'unknown';
        }
        return $state;
    }
}
