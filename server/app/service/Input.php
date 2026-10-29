<?php

declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Validation of untrusted text at the backend boundary. */
final class Input
{
    public static function text(mixed $value, int $max = 20000): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max) throw new InvalidArgumentException("Enter between 1 and $max characters.");
        return trim($value);
    }
}
