<?php

namespace App\Modules\AgentCore\Contracts;

use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\SharedConversationContext;

interface BusinessHandlerInterface
{
    public function supports(FinalTurnDecision $decision): bool;

    public function buildPayload(
        FinalTurnDecision $decision,
        SharedConversationContext $context,
    ): ?BusinessResponsePayload;
}
