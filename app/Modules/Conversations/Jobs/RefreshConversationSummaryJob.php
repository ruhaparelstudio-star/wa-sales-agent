<?php

namespace App\Modules\Conversations\Jobs;

use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshConversationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $conversationId,
    ) {}

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            Log::warning('[RefreshSummary] Conversation not found', ['id' => $this->conversationId]);
            return;
        }

        if ($conversation->messages()->where('message_type', MessageType::Text->value)->doesntExist()) {
            Log::info('[RefreshSummary] No text messages — skipping', ['conversation_id' => $this->conversationId]);
            return;
        }

        $orchestrator->generateSummary($conversation);
    }
}
