<?php

declare(strict_types=1);

namespace app\record;

use Crab\Database\ColumnName;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public string $id,
        public string $name,
        public string $avatar_url,
        #[ColumnName('created_at')] public DateTimeImmutable $createdAt,
    ) {}
}
