<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use app\service\MigrationSchema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        MigrationSchema::createOrValidate($schema->connection(), 'approvals', <<<'SQL'
CREATE TABLE IF NOT EXISTS approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL REFERENCES chats(id),
    rpc_id TEXT NOT NULL,
    method TEXT NOT NULL,
    details TEXT NOT NULL,
    decision TEXT
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE approvals');
    }
};
