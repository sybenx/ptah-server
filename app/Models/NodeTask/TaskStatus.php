<?php

namespace App\Models\NodeTask;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isEnded(): bool
    {
        return $this->value === TaskStatus::Completed->value
            || $this->value === TaskStatus::Failed->value
            || $this->value === TaskStatus::Cancelled->value;
    }
}