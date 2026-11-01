#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/app.php';
$database = $app->container->get(Crab\Contracts\Database\Database::class);
$database->table('projects')->insertOrIgnore([
    'id' => 'crabase',
    'name' => 'Crabase',
    'path' => dirname(__DIR__, 2),
]);
