<?php
return [
    'address' => 'tcp://127.0.0.1:8787',
    'workers' => 1,
    'with' => ['codex' => true],
    'max_body' => 10 * 1024 * 1024,
    'body_timeout' => 30,
];
