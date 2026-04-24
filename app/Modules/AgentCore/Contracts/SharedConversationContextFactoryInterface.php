<?php

namespace App\Modules\AgentCore\Contracts;

use App\Modules\AgentCore\DTOs\SharedConversationContext;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;

interface SharedConversationContextFactoryInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function make(
        Conversation $conversation,
        ?Lead $lead = null,
        ?Message $latestMessage = null,
        array $options = [],
    ): SharedConversationContext;
}
