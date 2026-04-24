<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;

final class PricelistInquiryHandlerInput
{
    public function __construct(
        public readonly Lead $lead,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly string $intent,
        public readonly string $deliveryStatus = 'resolve',
        public readonly ?string $relativePath = null,
    ) {}
}
