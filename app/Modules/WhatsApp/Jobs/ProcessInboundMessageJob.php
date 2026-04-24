<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\AgentCore\Enums\TurnOutcomeType;
use App\Modules\AgentCore\Jobs\RunAgentCoreJob;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Services\ConversationLockService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\MediaHandlerService;
use App\Modules\Conversations\Services\MessageIngestService;
use App\Modules\WhatsApp\Services\DuplicateMessageGuardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly string $agentId,
        public readonly string $from,
        public readonly ?string $fromJid,
        public readonly string $type,
        public readonly ?string $content,
        public readonly string $waMessageId,
        public readonly string $timestamp,
        public readonly ?string $caption = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $quotedWaMessageId = null,
        public readonly ?string $quotedFromJid = null,
        public readonly ?string $quotedContent = null,
        public readonly ?string $webhookIdempotencyKey = null,
    ) {}

    public function handle(
        MessageIngestService $ingestService,
        MediaHandlerService $mediaHandler,
        ConversationStateService $conversationStateService,
        DuplicateMessageGuardService $duplicateGuard,
        ConversationLockService $conversationLockService,
    ): void
    {
        $traceId = AgentLog::newTraceId();
        AgentLog::bind($traceId);

        AgentLog::info('inbound.received', [
            'agent_id'      => $this->agentId,
            'from'          => $this->from,
            'type'          => $this->type,
            'wa_message_id' => $this->waMessageId,
        ]);

        Log::info('[ProcessInboundMessage] Processing', [
            'trace_id'      => $traceId,
            'agent_id'      => $this->agentId,
            'from'          => $this->from,
            'type'          => $this->type,
            'wa_message_id' => $this->waMessageId,
        ]);

        $conversationLockService->blockForInbound(
            agentId: $this->agentId,
            fromJid: $this->fromJid,
            fromPhone: $this->from,
            callback: function () use ($ingestService, $mediaHandler, $conversationStateService, $duplicateGuard, $traceId): void {
                $receipt = $duplicateGuard->startInbound([
                    'agent_id' => $this->agentId,
                    'from' => $this->from,
                    'from_jid' => $this->fromJid,
                    'type' => $this->type,
                    'content' => $this->content ?? $this->caption,
                    'wa_message_id' => $this->waMessageId,
                    'media_url' => $this->mediaUrl,
                    'media_mime' => $this->mediaMime,
                    'quoted_wa_message_id' => $this->quotedWaMessageId,
                    'quoted_from_jid' => $this->quotedFromJid,
                    'quoted_content' => $this->quotedContent,
                ], $this->webhookIdempotencyKey);

                if ($duplicateGuard->shouldSkipInbound($receipt)) {
                    Log::info('[ProcessInboundMessage] Duplicate inbound skipped', [
                        'agent_id' => $this->agentId,
                        'wa_message_id' => $this->waMessageId,
                        'status' => $receipt->status?->value,
                    ]);
                    return;
                }

                if ($this->shouldIgnorePayload()) {
                    Log::info('[ProcessInboundMessage] Ignoring unsupported payload', [
                        'agent_id' => $this->agentId,
                        'wa_message_id' => $this->waMessageId,
                        'type' => $this->type,
                    ]);
                    $duplicateGuard->markInboundIgnored($receipt, 'unsupported_empty_payload');
                    return;
                }

                try {
                    $message = $receipt->message ?? $ingestService->ingestInbound([
                        'agent_id' => $this->agentId,
                        'from' => $this->from,
                        'from_jid' => $this->fromJid,
                        'type' => $this->type,
                        'content' => $this->content ?? $this->caption,
                        'wa_message_id' => $this->waMessageId,
                        'provider_idempotency_key' => $this->webhookIdempotencyKey,
                        'media_url' => $this->mediaUrl,
                        'media_mime' => $this->mediaMime,
                        'media_filename' => null,
                        'quoted_wa_message_id' => $this->quotedWaMessageId,
                        'quoted_from_jid' => $this->quotedFromJid,
                        'quoted_content' => $this->quotedContent,
                    ]);

                    $duplicateGuard->attachInboundMessage($receipt, $message);
                    $conversationStateService->recordInboundMessage($message);

                    $tenant = $message->lead->tenant;

                    if ($ingestService->isMediaMessage($message)) {
                        $mediaHandler->handleInboundMedia($message, $tenant);

                        // Finalize next_best_action even for the no-reply media path
                        // so the column is not left null between turns
                        // (System Audit Report §14 / WS-6).
                        $conversationStateService->recordNoReplyOutcome(
                            $message->conversation,
                            $message->lead,
                            TurnOutcomeType::NoReplyMediaReceived->value,
                        );

                        // Media path intentionally does not dispatch an auto-reply today.
                        // Log an honest no-reply outcome so observability matches behavior
                        // (previously this logged "Media auto-reply queued" without ever
                        // queueing a send — see System Audit Report §17 / WS-8).
                        $mediaOutcomePayload = [
                            'tenant_id' => $message->tenant_id,
                            'lead_id' => $message->lead_id,
                            'conversation_id' => $message->conversation_id,
                            'message_id' => $message->id,
                            'message_type' => $message->message_type,
                            'outcome' => TurnOutcomeType::NoReplyMediaReceived->value,
                            'reason' => TurnOutcomeType::NoReplyMediaReceived->value,
                        ];
                        AgentLog::info('turn.outcome', $mediaOutcomePayload);
                        // Backward-compatible alias for existing log consumers.
                        AgentLog::info('turn.no_reply_exit', $mediaOutcomePayload);

                        $duplicateGuard->markInboundProcessed($receipt, $message);
                        return;
                    }

                    if ($receipt->agent_core_dispatched_at === null) {
                        Log::info('[ProcessInboundMessage] Dispatching to AgentCore', [
                            'message_id' => $message->id,
                            'conversation_id' => $message->conversation_id,
                        ]);

                        RunAgentCoreJob::dispatch($message, $traceId)->onQueue('high');
                        $duplicateGuard->markAgentCoreDispatched($receipt);
                    }

                    $duplicateGuard->markInboundProcessed($receipt, $message);
                } catch (\Throwable $error) {
                    $duplicateGuard->markInboundFailed($receipt, $error);
                    throw $error;
                }
            },
        );
    }

    private function shouldIgnorePayload(): bool
    {
        if ($this->type !== 'unsupported') {
            return false;
        }

        return blank($this->content)
            && blank($this->caption)
            && blank($this->mediaUrl)
            && blank($this->mediaMime);
    }
}
