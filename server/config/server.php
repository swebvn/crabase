<?php
$config = require __DIR__.'/crabase.php';
return [
    'address' => 'tcp://'.$config['bind_host'].':8787',
    'workers' => 1,
    'with' => ['codex' => true],
    'max_body' => 10 * 1024 * 1024,
    'body_timeout' => 30,
];
