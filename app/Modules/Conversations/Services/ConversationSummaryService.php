<?php

namespace App\Modules\Conversations\Services;

use App\Modules\Conversations\Jobs\RefreshConversationSummaryJob;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationSummary;

class ConversationSummaryService
{
    public function refresh(Conversation $conv): void
    {
        RefreshConversationSummaryJob::dispatch($conv->id)->onQueue('low');
    }

    public function getSummary(Conversation $conv): ?string
    {
        return ConversationSummary::where('conversation_id', $conv->id)
            ->value('summary_text');
    }
}
