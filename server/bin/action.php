<?php
// Runs one already-authorized workspace action for the Codex worker so slow Git calls never block its event loop.
// Usage: php action.php JSON  where JSON is {"actor": {...}, "action": "...", "data": {...}}; prints {"result"} or {"error"}.
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $request = json_decode($argv[1] ?? '', true, 64, JSON_THROW_ON_ERROR);
    echo json_encode(['result' => App\Service\Actions::request($request['actor'], $request['action'], $request['data'])], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    fwrite(STDERR, $e."\n");
    echo json_encode(['error' => 'Unable to save changes. Please try again.']);
}
