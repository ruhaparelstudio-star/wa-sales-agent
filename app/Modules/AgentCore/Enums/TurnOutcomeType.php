<?php

namespace App\Modules\AgentCore\Enums;

enum TurnOutcomeType: string
{
    case Replied = 'replied';
    case FallbackReplied = 'fallback_replied';
    case NoReply = 'no_reply';
    case HandoffRequested = 'handoff_requested';
    case DispatchFailed = 'dispatch_failed';
    case Skipped = 'skipped';

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
