<?php

declare(strict_types=1);

namespace app\service;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Crab\Database\DatabaseManager;

/** Connects the legacy application facade to Crab's configured database pool. */
final class StoreProvider implements Provider
{
    public function register(Container $container): void
    {
        Store::useManager($container->get(DatabaseManager::class));
    }
}
