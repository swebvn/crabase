<?php

declare(strict_types=1);

namespace app\record;

final readonly class AuthSession
{
    public function __construct(
        public string $token_hash,
        public string $user_id,
        #[UnixTimestamp] public \DateTimeImmutable $expires,
    ) {}
}
