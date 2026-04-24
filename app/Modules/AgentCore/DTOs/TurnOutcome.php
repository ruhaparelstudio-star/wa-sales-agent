<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Enums\TurnOutcomeType;

final class TurnOutcome
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $outbound
     * @param  array<string, mixed>  $fallback
     * @param  array<string, int|float|null>  $timing
     */
    public function __construct(
        public readonly string $turnId,
        public readonly string $conversationId,
        public readonly ?string $decisionIntent,
        public readonly FinalAction|string|null $decisionAction,
        public readonly TurnOutcomeType $outcome,
        public readonly array $outbound,
        public readonly array $fallback,
        public readonly ?string $noReplyReason = null,
        public readonly array $timing = [],
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     turn_id: string,
     *     conversation_id: string,
     *     decision_intent: ?string,
     *     decision_action: ?string,
     *     outcome: string,
     *     outbound: array<string, mixed>,
     *     fallback: array<string, mixed>,
     *     no_reply_reason: ?string,
     *     timing: array<string, int|float|null>
     * }
     */
    public function toArray(): array
    {
        $decisionAction = $this->decisionAction instanceof FinalAction
            ? $this->decisionAction->value
            : $this->decisionAction;

        return [
            'schema_version' => $this->schemaVersion,
            'turn_id' => $this->turnId,
            'conversation_id' => $this->conversationId,
            'decision_intent' => $this->decisionIntent,
            'decision_action' => $decisionAction,
            'outcome' => $this->outcome->value,
            'outbound' => $this->normalizeValue($this->outbound),
            'fallback' => $this->normalizeValue($this->fallback),
            'no_reply_reason' => $this->noReplyReason,
            'timing' => $this->normalizeValue($this->timing),
        ];
    }
}
