<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute("CREATE TABLE users (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            avatar_url TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL
        )");
        $database->table('users')->insertMany([
            ['id' => bin2hex(random_bytes(8)), 'name' => 'user1', 'avatar_url' => 'https://i.pravatar.cc/96?img=12', 'created_at' => gmdate('c')],
            ['id' => bin2hex(random_bytes(8)), 'name' => 'user2', 'avatar_url' => 'https://i.pravatar.cc/96?img=47', 'created_at' => gmdate('c')],
        ]);
        $database->execute("INSERT INTO users (id,name,created_at)
            SELECT lower(hex(randomblob(8))), author, MIN(created_at) FROM messages
            WHERE role IN ('user','note') AND author NOT IN (SELECT name FROM users) GROUP BY author");
    }

    public function down(Schema $schema): void
    {
        $schema->connection()->execute('DROP TABLE users');
    }
};
