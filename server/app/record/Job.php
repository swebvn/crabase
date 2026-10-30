<?php

declare(strict_types=1);

namespace app\record;

/** A queued or running agent job. */
final readonly class Job
{
    public function __construct(
        public int $id,
        public string $chat_id,
        public string $prompt,
        public JobStatus $status,
        public ?string $turn_id,
        public bool $cancel,
        public ?string $model,
        public ?string $effort,
        public ?int $message_id = null,
    ) {
    }
}
