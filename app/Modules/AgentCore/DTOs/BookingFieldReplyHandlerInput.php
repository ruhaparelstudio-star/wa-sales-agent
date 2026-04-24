<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\Booking\Enums\FormType;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;

final class BookingFieldReplyHandlerInput
{
    public function __construct(
        public readonly Lead $lead,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly FormType $formType = FormType::Booking,
    ) {}
}
