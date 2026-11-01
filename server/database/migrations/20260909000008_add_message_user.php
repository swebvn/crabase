<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('ALTER TABLE messages ADD COLUMN user_id TEXT REFERENCES users(id)');
        $database->execute("UPDATE messages SET user_id=(SELECT id FROM users WHERE name=messages.author) WHERE role IN ('user','note')");
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('ALTER TABLE messages DROP COLUMN user_id');
    }
};
