<?php

declare(strict_types=1);

namespace app\record;

use Crab\Database\Json;
use DateTimeImmutable;

/** A human message to insert; its attachments are stored as JSON. */
final readonly class NewMessage
{
    public function __construct(
        public string $chat_id,
        public MessageRole $role,
        public string $author,
        public string $body,
        public DateTimeImmutable $created_at,
        public ?string $user_id,
        #[Json] public array $attachments,
    ) {
    }
}
