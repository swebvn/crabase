<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        // Outbox of per-model-call token usage, sent to Console Event Relay as `ai.tokens.used` by email.
        $schema->connection()->execute('CREATE TABLE token_usages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usage_id TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            total_tokens INTEGER NOT NULL CHECK (total_tokens >= 0),
            occurred_at TEXT NOT NULL,
            sent_at TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT,
            last_error TEXT
        )');
        $schema->connection()->execute('CREATE INDEX token_usages_pending ON token_usages (sent_at, next_attempt_at)');
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE token_usages');
    }
};
