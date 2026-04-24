<?php

namespace App\Modules\AgentCore\Contracts;

use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\TurnDecisionInput;

interface TurnDecisionServiceInterface
{
    public function decide(TurnDecisionInput $input): FinalTurnDecision;
}
