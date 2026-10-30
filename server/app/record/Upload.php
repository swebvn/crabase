<?php

declare(strict_types=1);

namespace app\record;

use DateTimeImmutable;

/** A pending upload, before it is attached to a message or saved as an avatar. */
final readonly class Upload
{
    public function __construct(
        public string $id,
        public string $user_id,
        public string $name,
        public int $size,
        public int $received,
        public ?string $mime,
        #[UnixTimestamp] public DateTimeImmutable $expires,
    ) {
    }
}
