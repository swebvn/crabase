<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $database = $schema->connection();
        // Each chat remembers the model and reasoning level its composer last used.
        $database->execute('ALTER TABLE chats ADD COLUMN model TEXT');
        $database->execute('ALTER TABLE chats ADD COLUMN effort TEXT');
        // Existing chats start from their latest agent request.
        $database->execute('UPDATE chats SET
            model = (SELECT j.model FROM jobs j WHERE j.chat_id = chats.id AND j.model IS NOT NULL ORDER BY j.id DESC LIMIT 1),
            effort = (SELECT j.effort FROM jobs j WHERE j.chat_id = chats.id AND j.model IS NOT NULL ORDER BY j.id DESC LIMIT 1)');
    }

    public function down(Schema $schema): void
    {
        $database = $schema->connection();
        $database->execute('ALTER TABLE chats DROP COLUMN effort');
        $database->execute('ALTER TABLE chats DROP COLUMN model');
    }
};
