<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute("CREATE TABLE accounts (
            user_id TEXT PRIMARY KEY REFERENCES users(id),
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            admin INTEGER NOT NULL DEFAULT 0 CHECK(admin IN (0,1)),
            enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
            git_name TEXT NOT NULL DEFAULT '', git_email TEXT NOT NULL DEFAULT ''
        )");
        $database->execute('CREATE TABLE auth_sessions (token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES accounts(user_id), expires INTEGER NOT NULL)');
        $database->execute('CREATE TABLE login_attempts (key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, expires INTEGER NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('DROP TABLE login_attempts');
        $database->execute('DROP TABLE auth_sessions');
        $database->execute('DROP TABLE accounts');
    }
};
