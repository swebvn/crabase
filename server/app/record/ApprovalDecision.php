<?php
declare(strict_types=1);
namespace app\record;
enum ApprovalDecision: string { case Accept = 'accept'; case Decline = 'decline'; }
