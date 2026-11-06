<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use App\Service\MigrationSchema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        MigrationSchema::createOrValidate($schema->connection(), 'messages', <<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL REFERENCES chats(id),
    role TEXT NOT NULL,
    author TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE messages');
    }
};
