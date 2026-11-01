<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute("ALTER TABLE projects ADD COLUMN visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private','public'))");
        $database->execute('CREATE TABLE project_members (
            project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            PRIMARY KEY (project_id, user_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('DROP TABLE project_members');
        $database->execute('ALTER TABLE projects DROP COLUMN visibility');
    }
};
