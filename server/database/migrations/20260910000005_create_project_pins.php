<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->connection()->execute('CREATE TABLE project_pins (
            user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            PRIMARY KEY (user_id, project_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE project_pins');
    }
};
