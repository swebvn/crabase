<?php

declare(strict_types=1);

namespace app\record;

use Crab\Database\ColumnName;
use Crab\Database\Json;
use DateTimeImmutable;

final readonly class Message
{
    public function __construct(
        public int $id,
        public string $chat_id,
        public MessageRole $role,
        public string $author,
        public string $body,
        #[ColumnName('created_at')] public DateTimeImmutable $createdAt,
        public ?string $user_id = null,
        #[Json] public array $attachments = [],
    ) {}
}
