<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\Conversations\Enums\ConversationStage;

class ConversationInterpretationService
{
    public function __construct(
        private readonly IntentExtractionService $intentExtractionService,
        private readonly SlotExtractionService $slotExtractionService,
    ) {}

    public function interpret(string $content, ?ClassifierOutput $classifier = null): InterpretationResult
    {
        $ruleResult = $this->intentExtractionService->extract($content);
        $ruleSlots = $this->slotExtractionService->extract($content);
        $classifierSlots = $classifier ? $this->mapClassifierFieldsToSlots($classifier->extractedFields) : [];
        $normalizedClassifierIntent = $classifier ? $this->normalizeLegacyIntent($classifier->intent) : null;

        $canonicalIntent = $ruleResult->canonicalIntent;
        $legacyIntent = $ruleResult->legacyIntent;
        $confidence = $ruleResult->confidence;
        $source = 'rules';

        if (! $ruleResult->hasClearIntent() && $classifier !== null) {
            $canonicalIntent = $this->canonicalIntentFromLegacy($normalizedClassifierIntent ?? $classifier->intent);
            $legacyIntent = $normalizedClassifierIntent ?? $classifier->intent;
            $confidence = max($classifier->confidence, $ruleResult->confidence);
            $source = $ruleSlots === [] ? 'llm' : 'llm+rules';
        } elseif ($classifier !== null) {
            $source = 'rules+llm';
            $confidence = max($ruleResult->confidence, min($classifier->confidence, 0.89));
        }

        $slots = $this->mergeSlots($classifierSlots, $ruleSlots);

        return new InterpretationResult(
            canonicalIntent: $canonicalIntent,
            legacyIntent: $legacyIntent,
            slots: $slots,
            confidence: min($confidence, 0.99),
            source: $source,
        );
    }

    public function mergeClassifierOutput(ClassifierOutput $classifier, InterpretationResult $interpretation): ClassifierOutput
    {
        return $this->resolveClassifierOutput($classifier, $interpretation)['classifier'];
    }

    /**
     * @param  array{protect_analyzer_intents?: list<string>, block_rule_ready_to_book?: bool}  $options
     * @return array{
     *     classifier: ClassifierOutput,
     *     raw_analyzer_intent: string,
     *     rule_intent: ?string,
     *     final_intent: string,
     *     override_reason: ?string,
     *     override_rejected_reason: ?string
     * }
     */
    public function resolveClassifierOutput(
        ClassifierOutput $classifier,
        InterpretationResult $interpretation,
        array $options = [],
    ): array {
        $normalizedClassifierIntent = $this->normalizeLegacyIntent($classifier->intent);
        $ruleIntent = $interpretation->hasClearIntent()
            ? $this->normalizeLegacyIntent($interpretation->legacyIntent)
            : null;
        $shouldPreferRules = $interpretation->hasClearIntent()
            && ($normalizedClassifierIntent === 'other' || $classifier->confidence < $interpretation->confidence);

        $intent = $shouldPreferRules ? ($ruleIntent ?? $normalizedClassifierIntent) : $normalizedClassifierIntent;
        $overrideReason = $shouldPreferRules
            ? 'prefer_rule_intent_over_analyzer'
            : 'keep_analyzer_intent';
        $overrideRejectedReason = null;

        $protectAnalyzerIntents = array_map(
            fn (string $intent): string => $this->normalizeLegacyIntent($intent),
            $options['protect_analyzer_intents'] ?? [],
        );

        if (
            ($options['block_rule_ready_to_book'] ?? false) === true
            && $intent === 'ready_to_book'
            && $ruleIntent === 'ready_to_book'
            && in_array($normalizedClassifierIntent, $protectAnalyzerIntents, true)
        ) {
            $intent = $normalizedClassifierIntent;
            $overrideReason = 'guard:protected_analyzer_intent_blocks_ready_to_book_rule';
            $overrideRejectedReason = $overrideReason;
        }

        if (
            $intent === 'payment_inquiry'
            && $ruleIntent === 'payment_inquiry'
            && in_array($normalizedClassifierIntent, ['tanya_paket', 'bandingkan_paket', 'tanya_harga'], true)
            && ! $this->hasExplicitPaymentSignal($interpretation->slots)
        ) {
            $intent = $normalizedClassifierIntent;
            $overrideReason = 'guard:package_or_price_intent_blocks_payment_rule';
            $overrideRejectedReason = $overrideReason;
        }

        $extractedFields = $this->mergeClassifierFields(
            $this->mapSlotsToClassifierFields($interpretation->slots),
            $classifier->extractedFields,
        );

        $resolved = new ClassifierOutput(
            intent: $intent,
            sentiment: $classifier->sentiment,
            extractedFields: $extractedFields,
            needsHandoff: $classifier->needsHandoff || in_array($intent, ['availability', 'custom_package', 'payment_proof', 'opt_out'], true),
            handoffReason: $classifier->handoffReason ?? $this->handoffReasonForIntent($intent),
            confidence: max($classifier->confidence, $interpretation->confidence),
            currentStage: $classifier->currentStage,
            suggestedNextStage: $classifier->suggestedNextStage,
            missingCriticalFields: $classifier->missingCriticalFields,
        );

        return [
            'classifier' => $resolved,
            'raw_analyzer_intent' => $normalizedClassifierIntent,
            'rule_intent' => $ruleIntent,
            'final_intent' => $intent,
            'override_reason' => $overrideReason,
            'override_rejected_reason' => $overrideRejectedReason,
        ];
    }

    public function toClassifierOutput(InterpretationResult $interpretation, ConversationStage $currentStage): ClassifierOutput
    {
        $legacyIntent = $interpretation->legacyIntent;

        return new ClassifierOutput(
            intent: $legacyIntent,
            sentiment: 'neutral',
            extractedFields: $this->mapSlotsToClassifierFields($interpretation->slots),
            needsHandoff: in_array($legacyIntent, ['availability', 'custom_package', 'payment_proof', 'opt_out'], true),
            handoffReason: $this->handoffReasonForIntent($legacyIntent),
            confidence: $interpretation->confidence,
            currentStage: $currentStage,
            suggestedNextStage: $this->suggestedStageForIntent($legacyIntent, $currentStage),
            missingCriticalFields: [],
        );
    }

    private function canonicalIntentFromLegacy(string $legacyIntent): string
    {
        return match ($this->normalizeLegacyIntent($legacyIntent)) {
            'greeting' => 'greeting',
            'tanya_harga' => 'price_inquiry',
            'tanya_paket', 'bandingkan_paket' => 'package_inquiry',
            'availability' => 'availability_inquiry',
            'payment_proof', 'payment_inquiry' => 'payment_inquiry',
            'ready_to_book' => 'booking_intent',
            'complaint' => 'objection',
            'opt_out' => 'opt_out',
            default => 'unclear',
        };
    }

    private function normalizeLegacyIntent(string $legacyIntent): string
    {
        return match (trim($legacyIntent)) {
            'package_recommendation' => 'tanya_paket',
            'objection_handling', 'clarification' => 'complaint',
            default => trim($legacyIntent),
        };
    }

    /**
     * @param  array<string, mixed>  $classifierFields
     * @return array<string, mixed>
     */
    private function mapClassifierFieldsToSlots(array $classifierFields): array
    {
        $slots = [];

        foreach ($classifierFields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match ($key) {
                'service_type' => $slots['event_type'] = $value,
                'event_date' => $slots['event_date'] = $value,
                'location' => $slots['location'] = $value,
                'budget' => $slots['budget'] = is_scalar($value) ? (string) $value : null,
                'pricing_focus' => $slots['pricing_focus'] = $value,
                'package_interest' => $slots['package_interest'] = $value,
                'payment_topic' => $slots['payment_topic'] = $value,
                'event_time_start' => $slots['event_time_start'] = $value,
                'event_time_end' => $slots['event_time_end'] = $value,
                default => null,
            };
        }

        return array_filter($slots, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function mapSlotsToClassifierFields(array $slots): array
    {
        $fields = [];

        foreach ($slots as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match ($key) {
                'event_type' => $fields['service_type'] = $value,
                'event_date' => $fields['event_date'] = $value,
                'location' => $fields['location'] = $value,
                'budget' => $fields['budget'] = $value,
                'pricing_focus' => $fields['pricing_focus'] = $value,
                'package_interest' => $fields['package_interest'] = $value,
                'payment_topic' => $fields['payment_topic'] = $value,
                'event_time_start' => $fields['event_time_start'] = $value,
                'event_time_end' => $fields['event_time_end'] = $value,
                default => null,
            };
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $secondary
     * @return array<string, mixed>
     */
    private function mergeSlots(array $primary, array $secondary): array
    {
        $merged = $primary;

        foreach ($secondary as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $secondary
     * @return array<string, mixed>
     */
    private function mergeClassifierFields(array $primary, array $secondary): array
    {
        $merged = $primary;

        foreach ($secondary as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized = $this->normalizeClassifierField($key, $value);

            if ($normalized === null || $normalized === '') {
                continue;
            }

            if (
                array_key_exists($key, $merged)
                && $merged[$key] !== null
                && $merged[$key] !== ''
                && $normalized !== $value
            ) {
                continue;
            }

            $merged[$key] = $normalized;
        }

        return $merged;
    }

    private function normalizeClassifierField(string $key, mixed $value): mixed
    {
        if ($key !== 'event_date' || ! is_scalar($value)) {
            return $value;
        }

        $normalized = $this->slotExtractionService->extract((string) $value);

        return $normalized['event_date'] ?? $value;
    }

    private function handoffReasonForIntent(string $intent): ?string
    {
        return match ($intent) {
            'availability' => 'availability_check',
            'payment_proof' => 'payment_proof',
            'complaint' => 'complaint',
            'opt_out' => 'opt_out',
            default => null,
        };
    }

    private function suggestedStageForIntent(string $intent, ConversationStage $currentStage): ?ConversationStage
    {
        return match ($intent) {
            'greeting' => $currentStage === ConversationStage::NewLead ? ConversationStage::Qualification : $currentStage,
            'tanya_harga', 'tanya_paket', 'bandingkan_paket' => in_array($currentStage, [ConversationStage::PaymentDiscussion, ConversationStage::Closing, ConversationStage::Booked], true)
                ? $currentStage
                : ConversationStage::PackageRecommendation,
            'payment_inquiry' => $currentStage === ConversationStage::Booked
                ? ConversationStage::Booked
                : ConversationStage::PaymentDiscussion,
            'ready_to_book' => ConversationStage::Closing,
            'complaint' => ConversationStage::ObjectionHandling,
            'availability', 'payment_proof', 'opt_out' => ConversationStage::HandoffToHuman,
            default => $currentStage,
        };
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasExplicitPaymentSignal(array $slots): bool
    {
        $paymentTopic = trim((string) ($slots['payment_topic'] ?? ''));

        return $paymentTopic !== '';
    }
}
