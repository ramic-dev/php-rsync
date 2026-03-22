<?php

declare(strict_types=1);

namespace Ramic\Rsync\Queue;

enum JobStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
}
