<?php
declare(strict_types=1);
namespace app\record;
enum JobStatus: string { case Queued = 'queued'; case Running = 'running'; case Done = 'done'; case Cancelled = 'cancelled'; case Failed = 'failed'; case Interrupted = 'interrupted'; case Completed = 'completed'; case Incomplete = 'incomplete'; case Aborted = 'aborted'; }
