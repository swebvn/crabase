<?php
$config = require __DIR__.'/crabase.php';
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $config['database_path'],
            'pool' => 1,
            'timeout' => 5,
            'migrations' => getenv('CRABASE_MIGRATIONS') ?: dirname(__DIR__).'/database/migrations',
        ],
    ],
];
