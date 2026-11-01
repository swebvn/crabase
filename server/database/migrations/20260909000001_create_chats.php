<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use app\service\MigrationSchema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        MigrationSchema::createOrValidate($schema->connection(), 'chats', <<<'SQL'
CREATE TABLE IF NOT EXISTS chats (
    id TEXT PRIMARY KEY,
    project_id TEXT REFERENCES projects(id),
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'idle',
    archived INTEGER NOT NULL DEFAULT 0,
    thread_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE chats');
    }
};
