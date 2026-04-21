<?php

namespace App\Modules\Knowledge\Enums;

enum KnowledgeStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected]);
    }
}
