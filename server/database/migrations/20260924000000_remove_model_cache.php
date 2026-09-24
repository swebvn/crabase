<?php

use Phinx\Migration\AbstractMigration;

final class RemoveModelCache extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("DELETE FROM settings WHERE key='models'");
    }

    public function down(): void
    {
        // The model catalog is intentionally runtime-only and cannot be restored.
    }
}
