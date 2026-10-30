<?php

declare(strict_types=1);

namespace app\record;

use Crab\Database\ColumnName;
use DateTimeImmutable;

/** A chat row as the application reads it. */
final readonly class Chat
{
    public function __construct(
        public string $id,
        public ?string $project_id,
        public string $title,
        public ChatStatus $status,
        public bool $archived,
        public ?string $thread_id,
        #[ColumnName('created_at')] public DateTimeImmutable $createdAt,
        #[ColumnName('updated_at')] public DateTimeImmutable $updatedAt,
    ) {
    }
}
