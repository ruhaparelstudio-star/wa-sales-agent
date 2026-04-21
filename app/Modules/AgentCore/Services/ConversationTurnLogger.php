<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Models\ConversationTurnLog;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use Illuminate\Support\Str;

/**
 * Collects per-turn signals and writes exactly one conversation_turn_logs row.
 * One instance per inbound message. Fields default to null until set.
 */
class ConversationTurnLogger
{
    private string $traceId;
    private int $startedAtMs;
    private ?int $tenantId = null;
    private ?int $conversationId = null;
    private ?int $messageId = null;

    private ?string $intent = null;
    private ?float $intentConfidence = null;
    private array $extractedSlots = [];
    private ?string $stageBefore = null;
    private ?string $stageAfter = null;
    private ?string $nextBestAction = null;
    private bool $fallbackUsed = false;
    private ?string $fallbackReason = null;
    private ?string $toolUsed = null;
    private ?string $responseType = null;
    private ?string $replyExcerpt = null;
    private ?array $evaluatorScore = null;
    private bool $flushed = false;

    public function __construct(?string $traceId = null)
    {
        $this->traceId = $traceId ?? AgentLog::newTraceId();
        $this->startedAtMs = (int) round(microtime(true) * 1000);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function bindMessage(Message $message, Conversation $conversation): self
    {
        $this->tenantId = (int) $conversation->tenant_id;
        $this->conversationId = (int) $conversation->id;
        $this->messageId = (int) $message->id;
        $this->stageBefore = $this->normalizeStage($conversation->stage);
        return $this;
    }

    public function setIntent(?string $intent, ?float $confidence): self
    {
        $this->intent = $intent;
        $this->intentConfidence = $confidence;
        return $this;
    }

    public function setExtractedSlots(array $slots): self
    {
        $this->extractedSlots = $slots;
        return $this;
    }

    public function setStageAfter(string|\BackedEnum|null $stage): self
    {
        $this->stageAfter = $this->normalizeStage($stage);
        return $this;
    }

    private function normalizeStage(string|\BackedEnum|null $stage): ?string
    {
        if ($stage instanceof \BackedEnum) {
            return (string) $stage->value;
        }
        return $stage;
    }

    public function setNextBestAction(?string $action): self
    {
        $this->nextBestAction = $action;
        return $this;
    }

    public function markFallback(string $reason): self
    {
        $this->fallbackUsed = true;
        $this->fallbackReason = $reason;
        return $this;
    }

    public function setTool(?string $tool): self
    {
        $this->toolUsed = $tool;
        return $this;
    }

    public function setResponse(string $responseType, ?string $replyText): self
    {
        $this->responseType = $responseType;
        $this->replyExcerpt = $replyText !== null ? Str::limit($replyText, 500, '') : null;
        return $this;
    }

    public function setEvaluatorScore(array $score): self
    {
        $this->evaluatorScore = $score;
        return $this;
    }

    public function getReplyExcerpt(): ?string
    {
        return $this->replyExcerpt;
    }

    public function getResponseType(): ?string
    {
        return $this->responseType;
    }

    public function getIntent(): ?string
    {
        return $this->intent;
    }

    public function flush(): void
    {
        if ($this->flushed || $this->tenantId === null || $this->conversationId === null) {
            return;
        }

        $this->flushed = true;
        $latency = (int) round(microtime(true) * 1000) - $this->startedAtMs;

        ConversationTurnLog::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'trace_id' => $this->traceId,
            'intent' => $this->intent,
            'intent_confidence' => $this->intentConfidence,
            'extracted_slots' => $this->extractedSlots !== [] ? $this->extractedSlots : null,
            'stage_before' => $this->stageBefore,
            'stage_after' => $this->stageAfter ?? $this->stageBefore,
            'next_best_action' => $this->nextBestAction,
            'fallback_used' => $this->fallbackUsed,
            'fallback_reason' => $this->fallbackReason,
            'tool_used' => $this->toolUsed,
            'response_type' => $this->responseType,
            'reply_excerpt' => $this->replyExcerpt,
            'evaluator_score' => $this->evaluatorScore,
            'latency_ms_total' => max(0, $latency),
        ]);

        AgentLog::info('turn.completed', [
            'conv' => $this->conversationId,
            'msg' => $this->messageId,
            'intent' => $this->intent,
            'stage_before' => $this->stageBefore,
            'stage_after' => $this->stageAfter,
            'response_type' => $this->responseType,
            'fallback' => $this->fallbackUsed,
            'eval' => $this->evaluatorScore,
            'latency_ms_total' => $latency,
        ]);
    }
}
