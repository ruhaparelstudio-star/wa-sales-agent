<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;
use App\Modules\Conversations\Enums\ConversationStage;

final class TurnDecisionInput
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $ruleSignals
     * @param  array<string, mixed>  $structuredState
     * @param  array<string, mixed>  $businessFlags
     */
    public function __construct(
        public readonly string $turnId,
        public readonly string $conversationId,
        public readonly ?string $leadId,
        public readonly SharedConversationContext $context,
        public readonly ?InterpretationResult $ruleInterpretation = null,
        public readonly ?ClassifierOutput $classifierResult = null,
        public readonly ?ConversationStage $currentStage = null,
        public readonly array $ruleSignals = [],
        public readonly array $structuredState = [],
        public readonly array $businessFlags = [],
        public readonly bool $fallbackEligible = false,
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'turn_id' => $this->turnId,
            'conversation_id' => $this->conversationId,
            'lead_id' => $this->leadId,
            'context' => $this->context->toArray(),
            'rule_interpretation' => $this->serializeRuleInterpretation(),
            'classifier_result' => $this->serializeClassifierResult(),
            'current_stage' => $this->currentStage?->value,
            'rule_signals' => $this->normalizeValue($this->ruleSignals),
            'structured_state' => $this->normalizeValue($this->structuredState),
            'business_flags' => $this->normalizeValue($this->businessFlags),
            'fallback_eligible' => $this->fallbackEligible,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeRuleInterpretation(): ?array
    {
        if ($this->ruleInterpretation === null) {
            return null;
        }

        return [
            'canonical_intent' => $this->ruleInterpretation->canonicalIntent,
            'legacy_intent' => $this->ruleInterpretation->legacyIntent,
            'slots' => $this->normalizeValue($this->ruleInterpretation->slots),
            'confidence' => $this->ruleInterpretation->confidence,
            'source' => $this->ruleInterpretation->source,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeClassifierResult(): ?array
    {
        if ($this->classifierResult === null) {
            return null;
        }

        return [
            'intent' => $this->classifierResult->intent,
            'sentiment' => $this->classifierResult->sentiment,
            'extracted_fields' => $this->normalizeValue($this->classifierResult->extractedFields),
            'needs_handoff' => $this->classifierResult->needsHandoff,
            'handoff_reason' => $this->classifierResult->handoffReason,
            'confidence' => $this->classifierResult->confidence,
            'current_stage' => $this->classifierResult->currentStage?->value,
            'suggested_next_stage' => $this->classifierResult->suggestedNextStage?->value,
            'missing_critical_fields' => array_values($this->normalizeValue($this->classifierResult->missingCriticalFields)),
        ];
    }
}
