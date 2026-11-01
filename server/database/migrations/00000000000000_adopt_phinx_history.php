<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;

/**
 * Moves an existing Phinx database onto Crab's migration ledger.
 *
 * The old ledger has no batch column, so adopted rows use batch zero. This
 * keeps the first Crab migration batch available for new work while leaving
 * the historical schema untouched.
 */
return new class implements Migration
{
    /** @var array<string, string> */
    private const FILES = [
        '20260909000000' => '20260909000000_create_projects',
        '20260909000001' => '20260909000001_create_chats',
        '20260909000002' => '20260909000002_create_messages',
        '20260909000003' => '20260909000003_create_jobs',
        '20260909000004' => '20260909000004_create_events',
        '20260909000005' => '20260909000005_create_approvals',
        '20260909000006' => '20260909000006_create_settings',
        '20260909000007' => '20260909000007_create_users',
        '20260909000008' => '20260909000008_add_message_user',
        '20260909000009' => '20260909000009_create_accounts',
        '20260909000010' => '20260909000010_add_job_message',
        '20260909000011' => '20260909000011_create_oauth',
        '20260909000012' => '20260909000012_create_attachments',
        '20260910000004' => '20260910000004_archive_projects',
        '20260910000005' => '20260910000005_create_project_pins',
        '20260910000006' => '20260910000006_project_worktrees',
        '20260910000007' => '20260910000007_project_permissions',
        '20260924000000' => '20260924000000_remove_model_cache',
    ];

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('phinxlog')) {
            return;
        }

        $database = $schema->connection();
        foreach ($database->table('phinxlog')->orderBy('version')->get() as $row) {
            $version = (string) $row['version'];
            $name = self::FILES[$version] ?? throw new RuntimeException(
                "The legacy Phinx migration {$version} is not known to this release.",
            );
            if ((int) ($row['breakpoint'] ?? 0) !== 0) {
                throw new RuntimeException("The legacy Phinx migration {$version} is still at a breakpoint.");
            }
            $ranAt = (string) ($row['end_time'] ?? $row['start_time'] ?? gmdate('Y-m-d H:i:s'));
            $database->table('migrations')->insertOrIgnore([
                'name' => $name,
                'batch' => 0,
                'ran_at' => $ranAt,
            ]);
        }

        // The Crab ledger is authoritative after this migration commits.
        $schema->drop('phinxlog');
    }

    public function down(Schema $schema): void
    {
        // Historical rows remain valid if this metadata-only migration is rolled back.
    }
};
