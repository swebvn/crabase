<?php

declare(strict_types=1);

use Crab\App;
use app\process\CodexServer;
use app\service\StoreProvider;

return App::configure(__DIR__)
    ->withProviders([StoreProvider::class, CodexServer::class])
    ->withRouting(routes: 'routes.php')
    // A reverse proxy on this host (Caddy) passes the browser address in X-Forwarded-For for login rate limits.
    ->withMiddleware(prepend: [new Crab\Http\TrustedProxies(['127.0.0.1', '::1'])])
    ->create();
