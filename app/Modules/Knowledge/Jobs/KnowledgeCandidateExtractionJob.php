<?php

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Knowledge\Services\KnowledgeCandidateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class KnowledgeCandidateExtractionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        private readonly int $conversationId,
    ) {
        $this->onQueue('low');
    }

    public function handle(KnowledgeCandidateService $service): void
    {
        $conv = Conversation::with(['lead', 'tenant', 'messages' => fn ($q) => $q->latest()->limit(20)])
            ->find($this->conversationId);

        if (! $conv || ! $conv->tenant) {
            return;
        }

        // Only extract from closed conversations with enough messages
        if ($conv->status !== ConversationStatus::Closed || $conv->messages->count() < 5) {
            return;
        }

        Log::info('[KnowledgeCandidateExtraction] Skipped — extraction pipeline not yet implemented', [
            'conversation_id' => $this->conversationId,
        ]);

        // TODO: implement LLM-based extraction from conversation transcript
        // $service->submit($conv->tenant, $conv, [...]);
    }
}
