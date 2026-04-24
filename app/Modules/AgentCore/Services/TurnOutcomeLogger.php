<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Enums\TurnOutcomeType;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Single end-of-turn outcome writer (System Audit Report §15 / WS-7).
 *
 * Shared between TurnLifecycleService and TurnPipelineService so both
 * lifecycle early-exits and pipeline branches emit exactly one structured
 * `turn.outcome` log per turn and finalize state through the same path.
 */
final class TurnOutcomeLogger
{
    public function __construct(
        private readonly ConversationStateService $conversationStateService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        Lead $lead,
        Conversation $conversation,
        Message $message,
        TurnOutcomeType $outcome,
        array $context = [],
        ?string $detail = null,
    ): void {
        $payload = array_merge([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'stage' => $conversation->stageEnum()->value,
            'outcome' => $outcome->value,
            'reason' => $detail ?? $outcome->value,
        ], $context);

        if ($outcome->isNoReply()) {
            // Finalize conversation_state.next_best_action exactly once
            // (System Audit Report §14 / WS-6).
            $this->conversationStateService->recordNoReplyOutcome(
                $conversation,
                $lead,
                $payload['reason'],
            );

            Log::warning(sprintf('[AgentOrchestrator] No reply exit: %s', $payload['reason']), $payload);
            AgentLog::warning('turn.outcome', $payload);
            // Backward-compatible alias for existing log consumers.
            AgentLog::warning('turn.no_reply_exit', $payload);
            return;
        }

        Log::info(sprintf('[AgentOrchestrator] Turn outcome: %s', $payload['reason']), $payload);
        AgentLog::info('turn.outcome', $payload);
    }
}
