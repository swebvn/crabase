<?php

declare(strict_types=1);

namespace app\record;

use Crab\Contracts\Database\Cast;
use DateTimeImmutable;
use DateTimeInterface;
use UnexpectedValueException;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class UnixTimestamp implements Cast
{
    public function read(mixed $value): DateTimeImmutable
    {
        $timestamp = is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)
            ? (int) $value
            : null;
        if ($timestamp === null) throw new UnexpectedValueException('it is not a Unix timestamp');
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }

    public function write(mixed $value): int
    {
        if (!$value instanceof DateTimeInterface) throw new UnexpectedValueException('it is not a date');
        return $value->getTimestamp();
    }
}
