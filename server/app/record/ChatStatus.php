<?php
declare(strict_types=1);
namespace app\record;
enum ChatStatus: string { case Idle = 'idle'; case Queued = 'queued'; case Running = 'running'; case Approval = 'approval'; }
