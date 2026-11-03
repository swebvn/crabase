<?php
foreach ([dirname(__DIR__,2).'/.env', dirname(__DIR__,3).'/.env'] as $env) if (is_file($env)) foreach (file($env, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$k,$v]=explode('=', $line, 2); if (getenv(trim($k))===false) putenv(trim($k).'='.trim($v, " \t\"'")); }
// Loopback ports for HTTP and the Codex WebSocket; change them when another app already uses 8787/8788.
$port = static function (string $name, int $default): int {
  $value = getenv($name);
  if ($value === false || $value === '') return $default;
  if (!ctype_digit($value) || (int)$value < 1024 || (int)$value > 65535) throw new RuntimeException("$name must be a port number from 1024 to 65535.");
  return (int)$value;
};
$httpPort = $port('CRABASE_HTTP_PORT', 8787);
$wsPort = $port('CRABASE_WS_PORT', 8788);
if ($httpPort === $wsPort) throw new RuntimeException('CRABASE_HTTP_PORT and CRABASE_WS_PORT must differ.');
return [
  'http_port' => $httpPort,
  'ws_port' => $wsPort,
  'bind_host' => getenv('CRABASE_BIND_HOST') ?: '127.0.0.1',
  'parallel_chats' => max(1, (int)(getenv('CRABASE_PARALLEL_CHATS') ?: 12)),
  'database_path' => getenv('CRABASE_DB') ?: dirname(__DIR__).'/runtime/crabase.sqlite',
  'agent_name' => getenv('CRABASE_AGENT_NAME') ?: 'Crab',
  'workspace_root' => getenv('CRABASE_WORKSPACE_ROOT') ?: dirname(__DIR__, 3),
];
