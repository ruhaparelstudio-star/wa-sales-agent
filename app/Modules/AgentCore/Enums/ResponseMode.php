<?php

namespace App\Modules\AgentCore\Enums;

enum ResponseMode: string
{
    case BusinessPayloadToResponder = 'business_payload_to_responder';
    case DirectText = 'direct_text';
    case FallbackText = 'fallback_text';
    case HandoffNotice = 'handoff_notice';
    case NoReply = 'no_reply';

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
