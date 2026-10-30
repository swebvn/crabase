<?php
declare(strict_types=1);
namespace app\record;
enum MessageRole: string { case User = 'user'; case Note = 'note'; case Assistant = 'assistant'; case Tool = 'tool'; case AgentActivity = 'agent_activity'; case Error = 'error'; }
