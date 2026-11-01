<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('ALTER TABLE projects ADD COLUMN parent_id TEXT REFERENCES projects(id) ON DELETE RESTRICT');
        $database->execute('CREATE INDEX projects_parent ON projects(parent_id)');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('DROP INDEX projects_parent');
        $database->execute('ALTER TABLE projects DROP COLUMN parent_id');
    }
};
