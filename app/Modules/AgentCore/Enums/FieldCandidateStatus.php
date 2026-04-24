<?php

namespace App\Modules\AgentCore\Enums;

enum FieldCandidateStatus: string
{
    case Candidate = 'candidate';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Invalid = 'invalid';

    public static function coerce(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
