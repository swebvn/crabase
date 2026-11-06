<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use App\Service\MigrationSchema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        MigrationSchema::createOrValidate($schema->connection(), 'jobs', <<<'SQL'
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL REFERENCES chats(id),
    prompt TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    turn_id TEXT,
    cancel INTEGER NOT NULL DEFAULT 0,
    model TEXT,
    effort TEXT
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE jobs');
    }
};
