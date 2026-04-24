<?php

namespace App\Modules\AgentCore\Support;

use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use Illuminate\Support\Facades\Log;

final class DecisionTrace
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function payload(FinalTurnDecision $decision, array $context = []): array
    {
        return array_merge($decision->toArray(), $context, [
            'conflict_count' => count($decision->conflicts),
            'note_count' => count($decision->notes),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(FinalTurnDecision $decision, array $context = []): void
    {
        $payload = self::payload($decision, $context);

        Log::info('[TurnDecision] Final turn decision resolved', $payload);
        AgentLog::info('turn.decision_trace', $payload);
    }
}
