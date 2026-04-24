<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;

final class FinalTurnDecision
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $decisionSource
     * @param  array<string, mixed>  $detectedSignals
     * @param  array<string, mixed>  $finalDecision
     * @param  list<mixed>  $missingFields
     * @param  list<mixed>  $fieldUpdates
     * @param  list<mixed>  $conflicts
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $turnId,
        public readonly string $conversationId,
        public readonly ?string $leadId,
        public readonly array $decisionSource,
        public readonly array $detectedSignals,
        public readonly array $finalDecision,
        public readonly array $missingFields = [],
        public readonly array $fieldUpdates = [],
        public readonly array $conflicts = [],
        public readonly array $notes = [],
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     turn_id: string,
     *     conversation_id: string,
     *     lead_id: ?string,
     *     decision_source: array<string, mixed>,
     *     detected_signals: array<string, mixed>,
     *     final_decision: array<string, mixed>,
     *     missing_fields: list<mixed>,
     *     field_updates: list<mixed>,
     *     conflicts: list<mixed>,
     *     notes: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'turn_id' => $this->turnId,
            'conversation_id' => $this->conversationId,
            'lead_id' => $this->leadId,
            'decision_source' => $this->normalizeValue($this->decisionSource),
            'detected_signals' => $this->normalizeValue($this->detectedSignals),
            'final_decision' => $this->normalizeValue($this->finalDecision),
            'missing_fields' => array_values($this->normalizeValue($this->missingFields)),
            'field_updates' => array_values($this->normalizeValue($this->fieldUpdates)),
            'conflicts' => array_values($this->normalizeValue($this->conflicts)),
            'notes' => array_values($this->notes),
        ];
    }
}
