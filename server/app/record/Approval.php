<?php

declare(strict_types=1);

namespace app\record;

final readonly class Approval
{
    public function __construct(
        public int $id,
        public string $chat_id,
        public string $rpc_id,
        public string $method,
        public string $details,
        public ?ApprovalDecision $decision = null,
    ) {}
}
