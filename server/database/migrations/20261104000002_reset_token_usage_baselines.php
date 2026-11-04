<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        // Baselines now track fresh (uncached) tokens; old ones included cached input, so each thread restarts from its next report.
        $schema->connection()->table('token_usage_threads')->delete();
    }

    public function down(Schema $schema): void
    {
        // Baselines re-establish themselves from the next report; nothing to restore.
    }
};
