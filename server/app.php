<?php

declare(strict_types=1);

use Crab\App;
use app\process\CodexServer;
use app\service\StoreProvider;

return App::configure(__DIR__)
    ->withProviders([StoreProvider::class, CodexServer::class])
    ->withRouting(routes: 'routes.php')
    ->create();
