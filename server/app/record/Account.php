<?php

declare(strict_types=1);

namespace app\record;

/**
 * Login credentials and account settings. It holds the password hash, so it is
 * read only where a password is checked or kept, and never sent to a browser.
 */
final readonly class Account
{
    public function __construct(
        public string $user_id,
        public string $email,
        public string $password_hash,
        public bool $admin,
        public bool $enabled,
        public string $git_name,
        public string $git_email,
    ) {
    }
}
