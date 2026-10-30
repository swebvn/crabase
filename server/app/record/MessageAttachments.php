<?php
declare(strict_types=1);
namespace app\record;
use Crab\Database\Json;
final readonly class MessageAttachments
{
    public function __construct(#[Json] public array $attachments) {}
}
