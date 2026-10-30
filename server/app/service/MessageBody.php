<?php

declare(strict_types=1);

namespace app\service;

final class MessageBody
{
    public static function append(int $id, string $delta): void
    {
        // The append must be one statement: reading and then saving the body can lose concurrent deltas.
        Store::notify(Store::connection()->execute('UPDATE messages SET body=body || ? WHERE id=?', [$delta, $id]));
    }
}
