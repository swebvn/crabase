<?php
$config = require __DIR__.'/crabase.php';
return [
    'address' => 'tcp://'.$config['bind_host'].':'.$config['http_port'],
    'workers' => 1,
    'max_body' => 10 * 1024 * 1024,
    'body_timeout' => 30,
];
