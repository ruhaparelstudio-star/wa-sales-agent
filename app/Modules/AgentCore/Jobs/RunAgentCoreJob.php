<?php

namespace App\Modules\AgentCore\Jobs;

use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAgentCoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 5;
    public int $timeout = 60;

    public function __construct(
        public readonly Message $message,
        public readonly ?string $traceId = null,
    ) {}

    public function handle(AgentOrchestrator $orchestrator, ConversationLockService $conversationLockService): void
    {
        $traceId = $this->traceId ?? AgentLog::newTraceId();
        AgentLog::bind($traceId);

        $message = $this->message->load(['lead.tenant', 'conversation']);

        $lead = $message->lead;
        $conv = $message->conversation;

        if (! $lead || ! $conv) {
            Log::warning('[RunAgentCore] Missing lead or conversation', [
                'trace_id'   => $traceId,
                'message_id' => $message->id,
            ]);
            return;
        }

        if ($conv->is_human_takeover) {
            AgentLog::info('automation.human_takeover', [
                'lead_id' => $lead->id,
                'conv' => $conv->id,
            ]);
            Log::info('[RunAgentCore] Human takeover active, skipping', [
                'trace_id' => $traceId,
                'lead_id' => $lead->id,
                'conversation_id' => $conv->id,
            ]);
            return;
        }

        if ($lead->automation_paused) {
            AgentLog::info('automation.paused', [
                'lead_id' => $lead->id,
                'conv' => $conv->id,
            ]);
            Log::info('[RunAgentCore] Automation paused, skipping', [
                'trace_id' => $traceId,
                'lead_id' => $lead->id,
            ]);
            return;
        }

        // Skip turns that have been superseded by a newer inbound message in
        // the same conversation. A second-level check exists in
        // TurnLifecycleService for defense in depth (System Audit Report
        // §16 / WS-9).
        $hasNewerInbound = $conv->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->where('id', '>', $message->id)
            ->exists();

        if ($hasNewerInbound) {
            AgentLog::info('turn.superseded', [
                'lead_id' => $lead->id,
                'conv' => $conv->id,
                'message_id' => $message->id,
            ]);
            Log::info('[RunAgentCore] Superseded by newer inbound, skipping', [
                'trace_id' => $traceId,
                'message_id' => $message->id,
            ]);
            return;
        }

        Log::info('[RunAgentCore] Dispatching orchestrator', [
            'trace_id'        => $traceId,
            'tenant_id'       => $lead->tenant_id,
            'lead_id'         => $lead->id,
            'conversation_id' => $conv->id,
            'message_id'      => $message->id,
        ]);

        $conversationLockService->blockForConversation($conv->id, function () use ($orchestrator, $message, $lead, $conv, $traceId): void {
            $orchestrator->handleInbound($message, $lead, $conv, $traceId);
        });
    }
}
