<?php

declare(strict_types=1);

namespace app\record;

use Crab\Database\ColumnName;
use DateTimeImmutable;

final readonly class Event
{
    public function __construct(
        public int $id,
        public ?string $chat_id,
        public string $label,
        #[ColumnName('created_at')] public DateTimeImmutable $createdAt,
    ) {}
}
