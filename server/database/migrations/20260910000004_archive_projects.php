<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->connection()->execute('ALTER TABLE projects ADD COLUMN archived INTEGER NOT NULL DEFAULT 0 CHECK(archived IN (0,1))');
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('ALTER TABLE projects DROP COLUMN archived');
    }
};
