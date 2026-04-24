<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Enums\TurnOutcomeType;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns lifecycle concerns for an inbound turn (System Audit Report §16 / WS-9):
 * superseded-message check, guardrail block, WhatsApp agent availability,
 * outcome logging for early exits, and turn-logger flush.
 *
 * Pipeline-level work (classifier → decide → dispatch → post-process → outbound)
 * is delegated to TurnPipelineService once early-exit checks pass.
 */
final class TurnLifecycleService
{
    public function __construct(
        private readonly TurnPipelineService $turnPipelineService,
        private readonly ResponseEvaluatorService $responseEvaluatorService,
        private readonly GuardrailService $guardrailService,
        private readonly TurnOutcomeLogger $turnOutcomeLogger,
    ) {}

    public function handleInbound(Message $message, Lead $lead, Conversation $conversation, ?string $traceId = null): void
    {
        $turnLogger = new ConversationTurnLogger($traceId);
        $turnLogger->bindMessage($message, $conversation);

        AgentLog::info('turn.started', [
            'conv' => $conversation->id,
            'msg' => $message->id,
            'stage_before' => $conversation->stage instanceof \BackedEnum ? $conversation->stage->value : $conversation->stage,
            'content_excerpt' => \Illuminate\Support\Str::limit((string) $message->content, 120, ''),
        ]);

        try {
            $agent = $this->resolveAgentOrExit($message, $lead, $conversation);
            if ($agent === null) {
                return;
            }

            if ($this->isSupersededByNewerInbound($message, $conversation)) {
                $this->turnOutcomeLogger->log(
                    $lead,
                    $conversation,
                    $message,
                    TurnOutcomeType::NoReplySuperseded,
                    ['detail' => 'newer_inbound_pending'],
                );
                return;
            }

            if ($this->isBlockedByGuardrail($message, $lead, $conversation)) {
                return;
            }

            $this->turnPipelineService->runInbound($message, $lead, $conversation, $agent, $turnLogger);
        } finally {
            try {
                $freshConversation = $conversation->fresh();
                $turnLogger->setStageAfter($freshConversation?->stage ?? $conversation->stage);
                $this->evaluateIfApplicable($turnLogger, $freshConversation ?? $conversation);
                $turnLogger->flush();
            } catch (Throwable $e) {
                Log::warning('[TurnLifecycleService] Turn log flush failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function resolveAgentOrExit(Message $message, Lead $lead, Conversation $conversation): ?WhatsAppAgent
    {
        $agent = $conversation->whatsapp_agent_id
            ? WhatsAppAgent::find($conversation->whatsapp_agent_id)
            : null;

        if ($agent === null) {
            Log::warning('[TurnLifecycle] No agent to dispatch with', [
                'conversation_id' => $conversation->id,
            ]);
            $this->turnOutcomeLogger->log(
                $lead,
                $conversation,
                $message,
                TurnOutcomeType::NoReplyAgentMissing,
                ['whatsapp_agent_id' => $conversation->whatsapp_agent_id],
            );
        }

        return $agent;
    }

    private function isBlockedByGuardrail(Message $message, Lead $lead, Conversation $conversation): bool
    {
        $guard = $this->guardrailService->check($lead, $conversation);
        if (! $guard->blocked) {
            return false;
        }

        Log::info('[TurnLifecycle] Guardrail blocked', [
            'tenant_id' => $lead->tenant_id,
            'lead_id'   => $lead->id,
            'reason'    => $guard->reason,
        ]);
        $this->turnOutcomeLogger->log(
            $lead,
            $conversation,
            $message,
            TurnOutcomeType::NoReplyGuardrail,
            ['guardrail_reason' => $guard->reason],
        );

        return true;
    }

    /**
     * True when a newer inbound message exists for the same conversation,
     * meaning this turn is stale and would race the newer one.
     */
    private function isSupersededByNewerInbound(Message $message, Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->where('id', '>', $message->id)
            ->exists();
    }

    private function evaluateIfApplicable(ConversationTurnLogger $logger, Conversation $conversation): void
    {
        $replyExcerpt = $logger->getReplyExcerpt();
        if ($replyExcerpt === null) {
            return;
        }

        $state = $conversation->state()->first();
        $score = $this->responseEvaluatorService->evaluate(
            $replyExcerpt,
            $conversation,
            null,
            $state,
            $logger->getResponseType() ?? 'text',
        );

        $logger->setEvaluatorScore($score);
    }
}
