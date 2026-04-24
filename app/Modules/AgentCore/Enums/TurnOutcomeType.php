<?php

namespace App\Modules\AgentCore\Enums;

enum TurnOutcomeType: string
{
    case Replied = 'replied';
    case FallbackReplied = 'fallback_replied';
    case HandoffRequested = 'handoff_requested';
    case DispatchFailed = 'dispatch_failed';
    case Skipped = 'skipped';

    // Generic no-reply (kept for backward compatibility; prefer the granular cases below).
    case NoReply = 'no_reply';

    // Granular no-reply reasons, replacing the ad-hoc string reasons collected
    // by the System Audit Report §15 / WS-7. Each case maps 1:1 to a previous
    // free-form reason string so log consumers stay stable.
    case NoReplyGuardrail = 'no_reply_guardrail';
    case NoReplyAgentMissing = 'no_reply_agent_missing';
    case NoReplyAgentDisconnected = 'no_reply_agent_disconnected';
    case NoReplyClassifierFailedNoRule = 'no_reply_classifier_failed_no_rule';
    case NoReplyClassifierFallbackEmpty = 'no_reply_classifier_fallback_empty';
    case NoReplyFallbackNonTransientError = 'no_reply_fallback_non_transient_error';
    case NoReplyFallbackAgentUnavailable = 'no_reply_fallback_agent_unavailable';
    case NoReplyDispatchDecision = 'no_reply_dispatch_decision';
    case NoReplyMediaReceived = 'no_reply_media_received';
    case NoReplySuperseded = 'no_reply_superseded';
    case NoReplyOutboundEmpty = 'no_reply_outbound_empty';
    case NoReplyOutboundDuplicate = 'no_reply_outbound_duplicate';

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

    /**
     * True for any case that represents a turn ending without an outbound reply.
     */
    public function isNoReply(): bool
    {
        return str_starts_with($this->value, 'no_reply');
    }
}
