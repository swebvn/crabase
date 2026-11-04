#!/usr/bin/env php
<?php
// Deterministic app-server protocol fixture: no network or model calls.
function emit(array $message): void { echo json_encode($message)."\n"; flush(); }
$thread = 0; $turn = 0;
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!isset($request['id'], $request['method'])) continue;
    $params = $request['params'];
    $result = [];
    switch ($request['method']) {
        case 'initialize': break;
        case 'model/list': $result = ['data'=>[]]; break;
        case 'thread/start': $result = ['thread'=>['id'=>'thread-'.++$thread]]; break;
        case 'thread/resume': $result = ['thread'=>['id'=>$params['threadId']]]; break;
        case 'turn/start':
            $id = 'turn-'.++$turn;
            emit(['method'=>'turn/started','params'=>['threadId'=>$params['threadId'],'turn'=>['id'=>$id,'status'=>'inProgress']]]);
            $text = $params['input'][0]['text'];
            if (str_starts_with($text, 'Participant context (JSON):')) $text = explode("\n\nCurrent message:\n", $text, 2)[1];
            emit(['method'=>'item/agentMessage/delta','params'=>['threadId'=>$params['threadId'],'turnId'=>$id,'itemId'=>'same-item-id','delta'=>$text]]);
            $result = ['turn'=>['id'=>$id]];
            break;
        case 'turn/interrupt':
        case 'test/complete':
            emit(['method'=>'turn/completed','params'=>['threadId'=>$params['threadId'],'turn'=>['id'=>$params['turnId'],'status'=>$request['method'] === 'turn/interrupt' ? 'interrupted' : 'completed']]]);
            break;
        case 'test/usage':
            emit(['method'=>'thread/tokenUsage/updated','params'=>['threadId'=>$params['threadId'],'turnId'=>$params['turnId'],'tokenUsage'=>[
                'last'=>['totalTokens'=>$params['last']], 'total'=>['totalTokens'=>$params['total'], 'cachedInputTokens'=>$params['cached'] ?? 0], 'modelContextWindow'=>null]]]);
            break;
        case 'test/fail':
            emit(['id'=>$request['id'],'error'=>['message'=>'One request failed']]);
            continue 2;
        case 'test/crash': exit(1);
    }
    emit(['id'=>$request['id'],'result'=>$result]);
}
