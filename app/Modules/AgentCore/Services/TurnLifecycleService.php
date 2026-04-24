<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TurnLifecycleService
{
    public function __construct(
        private readonly TurnPipelineService $turnPipelineService,
        private readonly ResponseEvaluatorService $responseEvaluatorService,
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
            $this->turnPipelineService->runInbound($message, $lead, $conversation, $turnLogger);
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
