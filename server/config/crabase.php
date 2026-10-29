<?php
foreach ([dirname(__DIR__,2).'/.env', dirname(__DIR__,3).'/.env'] as $env) if (is_file($env)) foreach (file($env, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$k,$v]=explode('=', $line, 2); if (getenv(trim($k))===false) putenv(trim($k).'='.trim($v, " \t\"'")); }
return [
  'bind_host' => getenv('CRABASE_BIND_HOST') ?: '127.0.0.1',
  'parallel_chats' => max(1, (int)(getenv('CRABASE_PARALLEL_CHATS') ?: 12)),
  'database_path' => getenv('CRABASE_DB') ?: dirname(__DIR__).'/runtime/crabase.sqlite',
  'agent_name' => getenv('CRABASE_AGENT_NAME') ?: 'Crab',
  'workspace_root' => getenv('CRABASE_WORKSPACE_ROOT') ?: dirname(__DIR__, 3),
];
