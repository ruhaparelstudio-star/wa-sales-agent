<?php

namespace App\Modules\AgentCore\Dispatch;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ConversationTurnLogger;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Models\WhatsAppAgent;

final class TurnDispatchContext
{
    public function __construct(
        public readonly Lead $lead,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly WhatsAppAgent $agent,
        public readonly ConversationTurnLogger $turnLogger,
        public readonly ?ClassifierOutput $classifier = null,
        public readonly ?InterpretationResult $interpretation = null,
    ) {}
}
