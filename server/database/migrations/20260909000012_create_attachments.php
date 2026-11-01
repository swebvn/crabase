<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute("ALTER TABLE messages ADD COLUMN attachments TEXT NOT NULL DEFAULT '[]'");
        $database->execute('CREATE TABLE uploads (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), name TEXT NOT NULL, size INTEGER NOT NULL, received INTEGER NOT NULL DEFAULT 0, mime TEXT, expires INTEGER NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('DROP TABLE uploads');
        $database->execute('ALTER TABLE messages DROP COLUMN attachments');
    }
};
