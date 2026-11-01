<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->connection()->table('settings')->where('key', 'models')->delete();
    }

    public function down(Schema $schema): void
    {
        // The model catalog is intentionally runtime-only and cannot be restored.
    }
};
