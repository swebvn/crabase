<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->connection()->execute('ALTER TABLE jobs ADD COLUMN message_id INTEGER REFERENCES messages(id)');
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('ALTER TABLE jobs DROP COLUMN message_id');
    }
};
