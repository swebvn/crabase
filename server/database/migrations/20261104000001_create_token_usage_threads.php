<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        // Last credited running total per Codex thread: only growth is credited, so repeated reports add nothing.
        $schema->connection()->execute('CREATE TABLE token_usage_threads (
            thread_id TEXT PRIMARY KEY,
            total_tokens INTEGER NOT NULL CHECK (total_tokens >= 0)
        )');
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE token_usage_threads');
    }
};
