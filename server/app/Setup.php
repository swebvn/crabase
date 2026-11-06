<?php

declare(strict_types=1);

namespace App;

use App\Service\Store;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Crab\Database\DatabaseManager;

/** The application's setup: connects the legacy Store facade to Crab's configured database pool. */
final class Setup implements Plugin
{
    public function register(Container $container): void
    {
        Store::useManager($container->get(DatabaseManager::class));
    }
}
