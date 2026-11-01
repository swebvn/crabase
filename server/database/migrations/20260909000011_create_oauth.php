<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('CREATE TABLE oauth_flows (state_hash TEXT PRIMARY KEY, browser_hash TEXT NOT NULL, verifier TEXT NOT NULL, expires INTEGER NOT NULL)');
        $database->execute('CREATE TABLE oauth_identities (provider TEXT NOT NULL, subject TEXT NOT NULL, user_id TEXT NOT NULL REFERENCES accounts(user_id), PRIMARY KEY(provider,subject), UNIQUE(provider,user_id))');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('DROP TABLE oauth_identities');
        $database->execute('DROP TABLE oauth_flows');
    }
};
