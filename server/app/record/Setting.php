<?php

declare(strict_types=1);

namespace app\record;

final readonly class Setting
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
