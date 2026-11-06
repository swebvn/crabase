<?php
declare(strict_types=1);
namespace App\Record;
enum ChatStatus: string { case Idle = 'idle'; case Queued = 'queued'; case Running = 'running'; case Approval = 'approval'; }
