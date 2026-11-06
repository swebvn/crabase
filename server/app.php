<?php

declare(strict_types=1);

use Crab\App;
use App\Setup;
use App\Process\CodexServer;

// Crabase uses only the core plugins; its setup connects Store and registers the persistent Codex WebSocket server.
return App::configure(__DIR__)
    ->withSetup([Setup::class, CodexServer::class])
    ->withRouting(routes: 'routes.php')
    // A reverse proxy on this host (Caddy) passes the browser address in X-Forwarded-For for login rate limits.
    ->withMiddleware(prepend: [new Crab\Http\TrustedProxies(['127.0.0.1', '::1'])])
    ->create();
