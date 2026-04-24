<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Contracts\TurnDecisionServiceInterface;
use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\TurnDecisionInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Enums\ResponseMode;
use App\Modules\Conversations\Enums\ConversationStage;

class TurnDecisionService implements TurnDecisionServiceInterface
{
    /**
     * @var array<string, int>
     */
    private const STAGE_ORDER = [
        'new_lead' => 0,
        'qualification' => 1,
        'needs_discovery' => 2,
        'package_recommendation' => 3,
        'objection_handling' => 4,
        'payment_discussion' => 5,
        'closing' => 6,
        'booked' => 7,
        'follow_up' => 8,
        'handoff_to_human' => 9,
        'closed' => 10,
    ];

    public function decide(TurnDecisionInput $input): FinalTurnDecision
    {
        $currentStage = $input->currentStage ?? ConversationStage::coerce($input->context->currentStage);
        $ruleIntent = $this->resolvedRuleIntent($input);
        $classifierIntent = $this->normalizeIntent($input->classifierResult?->intent);
        $guardOverrideIntent = $this->normalizeIntent($input->businessFlags['guard_override_intent'] ?? null);
        $guardOverrideStage = ConversationStage::coerce($input->businessFlags['guard_override_stage'] ?? null);
        $guardOverrideReason = $this->stringOrNull($input->businessFlags['guard_override_reason'] ?? null);
        $handoffRequired = (bool) ($input->businessFlags['handoff_required'] ?? ($input->classifierResult?->needsHandoff ?? false));
        $handoffReason = $this->stringOrNull($input->businessFlags['handoff_reason'] ?? $input->classifierResult?->handoffReason);
        $negativeSentiment = (bool) ($input->businessFlags['negative_sentiment'] ?? false);
        $noReply = (bool) ($input->businessFlags['no_reply'] ?? false);
        $noReplyReason = $this->stringOrNull($input->businessFlags['no_reply_reason'] ?? null);
        $forceFallbackReason = $this->stringOrNull($input->businessFlags['force_fallback_reason'] ?? null);
        $forceHandoff = (bool) ($input->businessFlags['force_handoff'] ?? false);

        $conflicts = [];
        $notes = [];
        $stageConsistencyUsed = false;

        if ($ruleIntent !== null && $classifierIntent !== null && $ruleIntent !== $classifierIntent) {
            $conflicts[] = [
                'type' => 'intent_mismatch',
                'rule_intent' => $ruleIntent,
                'classifier_intent' => $classifierIntent,
                'stage_before' => $currentStage?->value,
            ];
        }

        $finalIntent = $classifierIntent ?? $ruleIntent ?? 'other';

        if ($guardOverrideIntent !== null) {
            $finalIntent = $guardOverrideIntent;
            $notes[] = 'Explicit guard override selected the final intent.';
        } elseif (
            $ruleIntent !== null
            && $classifierIntent !== null
            && $ruleIntent !== $classifierIntent
            && $classifierIntent === 'ready_to_book'
            && (bool) ($input->businessFlags['ready_to_book_allowed'] ?? false)
        ) {
            $finalIntent = $classifierIntent;
            $notes[] = 'Booking intent remained in control because booking prerequisites are already satisfied.';
        } elseif ($ruleIntent !== null && $classifierIntent !== null && $ruleIntent !== $classifierIntent) {
            $stageConsistentIntent = $this->chooseStageConsistentIntent($ruleIntent, $classifierIntent, $currentStage);

            if ($stageConsistentIntent !== null) {
                $finalIntent = $stageConsistentIntent;
                $stageConsistencyUsed = true;
                $notes[] = 'Rule and classifier conflicted; stage-consistent intent was selected.';
            } elseif ($classifierIntent === 'other') {
                $finalIntent = $ruleIntent;
                $notes[] = 'Classifier stayed unclear, so the clearer rule intent was retained.';
            } else {
                $finalIntent = $classifierIntent;
                $notes[] = 'Rule and classifier conflicted; classifier intent stayed in control because no stage-consistent override applied.';
            }
        } elseif ($ruleIntent !== null && $classifierIntent === null) {
            $finalIntent = $ruleIntent;
            $notes[] = 'Classifier result was unavailable, so rule intent was used.';
        } elseif ($ruleIntent !== null && $classifierIntent === 'other') {
            $finalIntent = $ruleIntent;
            $notes[] = 'Classifier returned other, so the clearer rule intent was used.';
        } elseif ($ruleIntent !== null && $ruleIntent === $classifierIntent) {
            $notes[] = 'Rule and classifier aligned.';
        } elseif ($classifierIntent !== null) {
            $notes[] = 'Classifier intent was used as the final intent.';
        }

        $requiresHandoff = $this->requiresHandoff(
            $finalIntent,
            $classifierIntent,
            $handoffRequired,
            $handoffReason,
            $guardOverrideIntent,
            $negativeSentiment,
            $forceHandoff,
        );

        if (! $requiresHandoff) {
            $handoffReason = null;
        }

        $action = $this->resolveAction(
            $finalIntent,
            $negativeSentiment,
            $requiresHandoff,
            $noReply,
            $input->fallbackEligible,
            $forceFallbackReason,
            (bool) ($input->businessFlags['booking_field_reply_candidate'] ?? false),
        );

        $stageAfter = $guardOverrideStage
            ?? $this->resolveStageAfter($finalIntent, $action, $currentStage);

        if ($negativeSentiment) {
            $notes[] = 'Negative sentiment requires human handoff.';
        }

        if ($noReply && $noReplyReason !== null) {
            $notes[] = 'No reply was selected because: ' . $noReplyReason;
        }

        if ($action === FinalAction::ReplyWithFallback) {
            $notes[] = 'Fallback action was selected because the turn is eligible for a safer fallback path.';
        }

        if ($action === FinalAction::RequestHumanHandoff && $handoffReason !== null) {
            $notes[] = 'Human handoff is required for reason: ' . $handoffReason;
        }

        $decisionSource = [
            'rules_used' => $ruleIntent !== null,
            'classifier_used' => $classifierIntent !== null,
            'guard_signals_used' => $guardOverrideIntent !== null || $guardOverrideReason !== null,
            'stage_consistency_used' => $stageConsistencyUsed,
            'fallback_eligible' => $input->fallbackEligible,
            'final_authority' => 'turn_decision_service',
        ];

        $detectedSignals = [
            'rule_intent' => $ruleIntent,
            'classifier_intent' => $classifierIntent,
            'guard_override_intent' => $guardOverrideIntent,
            'guard_override_reason' => $guardOverrideReason,
            'current_stage' => $currentStage?->value,
            'active_topic' => $input->context->activeTopic,
            'fallback_eligible' => $input->fallbackEligible,
            'negative_sentiment' => $negativeSentiment,
            'handoff_requested' => $requiresHandoff,
            'handoff_reason' => $handoffReason,
            'next_expected_field' => $input->context->nextExpectedField,
            'latest_user_ask' => $input->context->latestUserAsk,
        ];

        $finalDecision = [
            'intent' => $finalIntent,
            'stage_before' => $currentStage?->value,
            'stage_after' => $stageAfter?->value ?? $currentStage?->value,
            'action' => $action,
            'response_mode' => $this->responseModeForAction($action),
            'fallback_reason' => $this->fallbackReason($action, $forceFallbackReason, $noReplyReason),
            'requires_handoff' => $requiresHandoff,
            'handoff_reason' => $handoffReason,
            'should_reply' => $action !== FinalAction::DoNotReply,
            'should_store_memory' => ! (bool) ($input->businessFlags['skip_memory_update'] ?? false),
            'confidence' => $this->resolveConfidence($input),
        ];

        return new FinalTurnDecision(
            turnId: $input->turnId,
            conversationId: $input->conversationId,
            leadId: $input->leadId,
            decisionSource: $decisionSource,
            detectedSignals: $detectedSignals,
            finalDecision: $finalDecision,
            missingFields: $this->missingFields($input),
            fieldUpdates: $this->fieldUpdates($input),
            conflicts: $conflicts,
            notes: array_values(array_unique($notes)),
        );
    }

    private function requiresHandoff(
        string $finalIntent,
        ?string $classifierIntent,
        bool $handoffRequired,
        ?string $handoffReason,
        ?string $guardOverrideIntent,
        bool $negativeSentiment,
        bool $forceHandoff,
    ): bool {
        if ($negativeSentiment || $forceHandoff) {
            return true;
        }

        if (in_array($finalIntent, ['availability', 'custom_package', 'payment_proof', 'opt_out'], true)) {
            return true;
        }

        if (! $handoffRequired) {
            return false;
        }

        if ($finalIntent === 'ready_to_book') {
            return false;
        }

        if ($guardOverrideIntent !== null && $finalIntent !== $classifierIntent) {
            return false;
        }

        return in_array($handoffReason, [
            'availability',
            'availability_check',
            'custom_package',
            'payment_proof',
            'opt_out',
            'complaint',
            'negative_sentiment',
        ], true);
    }

    private function resolvedRuleIntent(TurnDecisionInput $input): ?string
    {
        if ($input->ruleInterpretation !== null && $input->ruleInterpretation->hasClearIntent()) {
            return $this->normalizeIntent($input->ruleInterpretation->legacyIntent);
        }

        return $this->normalizeIntent($input->ruleSignals['rule_intent'] ?? null);
    }

    private function resolveAction(
        string $finalIntent,
        bool $negativeSentiment,
        bool $handoffRequired,
        bool $noReply,
        bool $fallbackEligible,
        ?string $forceFallbackReason,
        bool $bookingFieldReplyCandidate,
    ): FinalAction {
        if ($noReply) {
            return FinalAction::DoNotReply;
        }

        // Opt-out has a specific confirmation message + automation pause semantics,
        // different from a generic human handoff. Keep it as its own action so the
        // dispatcher can pick the opt-out-specific flow.
        if ($finalIntent === 'opt_out') {
            return FinalAction::ReplyWithOptOut;
        }

        if ($negativeSentiment || $handoffRequired || in_array($finalIntent, ['availability', 'custom_package', 'payment_proof'], true)) {
            return FinalAction::RequestHumanHandoff;
        }

        if ($bookingFieldReplyCandidate) {
            return FinalAction::AskForBookingField;
        }

        if ($forceFallbackReason !== null) {
            return FinalAction::ReplyWithFallback;
        }

        return match ($finalIntent) {
            'ready_to_book' => FinalAction::GuideToBooking,
            'bandingkan_paket' => FinalAction::ReplyWithPackageComparison,
            'tanya_harga' => FinalAction::ReplyWithPriceDetails,
            'tanya_paket' => FinalAction::ReplyWithPackageDetails,
            default => FinalAction::RespondToUser,
        };
    }

    private function responseModeForAction(FinalAction $action): ResponseMode
    {
        return match ($action) {
            FinalAction::ReplyWithPackageDetails,
            FinalAction::ReplyWithPriceDetails,
            FinalAction::AskForBookingField => ResponseMode::BusinessPayloadToResponder,
            FinalAction::ReplyWithFallback => ResponseMode::FallbackText,
            FinalAction::RequestHumanHandoff => ResponseMode::HandoffNotice,
            FinalAction::ReplyWithOptOut => ResponseMode::DirectText,
            FinalAction::DoNotReply => ResponseMode::NoReply,
            default => ResponseMode::DirectText,
        };
    }

    private function resolveStageAfter(
        string $finalIntent,
        FinalAction $action,
        ?ConversationStage $currentStage,
    ): ?ConversationStage {
        if ($action === FinalAction::DoNotReply) {
            return $currentStage;
        }

        if ($action === FinalAction::RequestHumanHandoff || $action === FinalAction::ReplyWithOptOut) {
            return ConversationStage::HandoffToHuman;
        }

        if ($action === FinalAction::ReplyWithFallback && $finalIntent === 'other') {
            return $currentStage;
        }

        return match ($finalIntent) {
            'greeting' => $currentStage === ConversationStage::NewLead ? ConversationStage::Qualification : $currentStage,
            'tanya_harga', 'tanya_paket', 'bandingkan_paket' => $this->preserveLateStage($currentStage, ConversationStage::PackageRecommendation),
            'payment_inquiry' => $currentStage === ConversationStage::Booked ? ConversationStage::Booked : ConversationStage::PaymentDiscussion,
            'ready_to_book' => ConversationStage::Closing,
            'complaint' => ConversationStage::ObjectionHandling,
            'availability', 'custom_package', 'payment_proof', 'opt_out' => ConversationStage::HandoffToHuman,
            default => $currentStage,
        };
    }

    private function preserveLateStage(?ConversationStage $currentStage, ConversationStage $fallback): ConversationStage
    {
        if ($currentStage !== null && in_array($currentStage, [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
            ConversationStage::Booked,
        ], true)) {
            return $currentStage;
        }

        return $fallback;
    }

    private function chooseStageConsistentIntent(
        string $ruleIntent,
        string $classifierIntent,
        ?ConversationStage $currentStage,
    ): ?string {
        if ($currentStage === null) {
            return null;
        }

        $ruleScore = $this->intentStageScore($ruleIntent, $currentStage);
        $classifierScore = $this->intentStageScore($classifierIntent, $currentStage);

        if ($ruleScore === $classifierScore) {
            return null;
        }

        return $ruleScore > $classifierScore ? $ruleIntent : $classifierIntent;
    }

    private function intentStageScore(string $intent, ConversationStage $currentStage): int
    {
        $targetStage = $this->intentAnchorStage($intent, $currentStage);
        if ($targetStage === null) {
            return 0;
        }

        $currentOrder = self::STAGE_ORDER[$currentStage->value] ?? 0;
        $targetOrder = self::STAGE_ORDER[$targetStage->value] ?? 0;

        if ($targetStage === $currentStage) {
            return 100;
        }

        return max(0, 50 - abs($currentOrder - $targetOrder));
    }

    private function intentAnchorStage(string $intent, ?ConversationStage $currentStage): ?ConversationStage
    {
        return match ($intent) {
            'greeting' => $currentStage === ConversationStage::NewLead ? ConversationStage::Qualification : $currentStage,
            'tanya_harga', 'tanya_paket', 'bandingkan_paket' => ConversationStage::PackageRecommendation,
            'payment_inquiry' => $currentStage === ConversationStage::Booked ? ConversationStage::Booked : ConversationStage::PaymentDiscussion,
            'ready_to_book' => ConversationStage::Closing,
            'complaint' => ConversationStage::ObjectionHandling,
            'availability', 'custom_package', 'payment_proof', 'opt_out' => ConversationStage::HandoffToHuman,
            default => $currentStage,
        };
    }

    /**
     * @return list<string>
     */
    private function missingFields(TurnDecisionInput $input): array
    {
        $missing = $input->classifierResult?->missingCriticalFields ?? [];

        foreach ($input->context->unresolvedQuestions as $question) {
            $question = trim((string) $question);

            if ($question !== '' && ! in_array($question, $missing, true)) {
                $missing[] = $question;
            }
        }

        if ($input->context->nextExpectedField !== null && ! in_array($input->context->nextExpectedField, $missing, true)) {
            $missing[] = $input->context->nextExpectedField;
        }

        return array_values($missing);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldUpdates(TurnDecisionInput $input): array
    {
        $updates = [];

        foreach ($input->ruleInterpretation?->slots ?? [] as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $updates[] = [
                'field' => $field,
                'value' => $value,
                'source' => 'rules',
            ];
        }

        foreach ($input->classifierResult?->extractedFields ?? [] as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $updates[] = [
                'field' => $field,
                'value' => $value,
                'source' => 'classifier',
            ];
        }

        return array_values($updates);
    }

    private function resolveConfidence(TurnDecisionInput $input): float
    {
        return round(max(
            $input->ruleInterpretation?->confidence ?? 0.0,
            $input->classifierResult?->confidence ?? 0.0,
        ), 2);
    }

    private function fallbackReason(FinalAction $action, ?string $forceFallbackReason, ?string $noReplyReason): ?string
    {
        return match ($action) {
            FinalAction::ReplyWithFallback => $forceFallbackReason ?? 'fallback_eligible',
            FinalAction::DoNotReply => $noReplyReason ?? 'do_not_reply',
            default => null,
        };
    }

    private function normalizeIntent(mixed $intent): ?string
    {
        $normalized = strtolower(trim((string) $intent));

        if ($normalized === '' || $normalized === 'unclear') {
            return null;
        }

        return match ($normalized) {
            'ask_package_details', 'package_details', 'ask_package_info', 'package_recommendation' => 'tanya_paket',
            'ask_price_details', 'price_inquiry' => 'tanya_harga',
            'package_comparison' => 'bandingkan_paket',
            'booking_intent' => 'ready_to_book',
            'objection_handling', 'clarification' => 'complaint',
            default => $normalized,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
