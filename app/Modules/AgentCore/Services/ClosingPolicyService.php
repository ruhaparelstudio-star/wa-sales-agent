<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;

class ClosingPolicyService
{
    public function __construct(
        private readonly LeadMemoryService $leadMemoryService,
        private readonly LeadBookingDataService $leadBookingDataService,
        private readonly LeadReadinessScorer $leadReadinessScorer,
        private readonly CtaSuggestionService $ctaSuggestionService,
    ) {}

    /**
     * @return array{
     *   readiness_score: int,
     *   readiness_label: string,
     *   strong_signals: list<string>,
     *   cta_level: string,
     *   answer_priority: string,
     *   next_best_action: string,
     *   booking_field_focus: string|null,
     *   payment_topic: string|null,
     *   canonical_intent: string,
     *   suggested_cta_style: string
     * }
     */
    public function resolve(
        Conversation $conversation,
        Lead $lead,
        ?ClassifierOutput $classifier = null,
        ?InterpretationResult $interpretation = null,
    ): array {
        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        $stage = $conversation->stageEnum();
        $canonicalIntent = $interpretation?->canonicalIntent
            ?? $this->canonicalIntentForClassifier($classifier?->intent)
            ?? 'unclear';

        $paymentTopic = $interpretation?->slots['payment_topic']
            ?? $snapshot['custom_fields']['payment_topic']
            ?? null;

        $packageInterest = $interpretation?->slots['package_interest']
            ?? (($snapshot['preferred_packages'] ?? [])[0] ?? null);

        $bookingMissing = $this->leadBookingDataService->getMissingRequired($lead, FormType::Booking);
        $bookingFieldFocus = $bookingMissing[0] ?? null;
        $bookingFieldsComplete = $bookingMissing === [];

        $qualificationComplete = $this->filled($snapshot['service_type'] ?? null)
            && $this->filled($snapshot['event_date'] ?? null)
            && $this->filled($snapshot['event_location'] ?? null);

        $discoveryComplete = $this->filled($snapshot['name'] ?? null)
            && $this->filled($snapshot['guest_count'] ?? null)
            && ($this->filled($snapshot['budget_min'] ?? null) || $this->filled($snapshot['budget_max'] ?? null));

        $hasPaymentSignal = $canonicalIntent === 'payment_inquiry'
            || $paymentTopic !== null
            || $stage === ConversationStage::PaymentDiscussion;

        $hasBookingSignal = $canonicalIntent === 'booking_intent'
            || $classifier?->intent === 'ready_to_book'
            || $stage === ConversationStage::Closing;

        $hasPackageSignal = $this->filled($packageInterest);

        $readiness = $this->leadReadinessScorer->score(
            $stage,
            $this->resolveLeadTemperature($lead),
            $qualificationComplete,
            $discoveryComplete,
            $hasPaymentSignal,
            $hasBookingSignal,
            $hasPackageSignal,
            $bookingFieldsComplete,
            count($bookingMissing),
        );

        $cta = $this->ctaSuggestionService->suggest(
            $stage,
            $canonicalIntent,
            $readiness,
            $paymentTopic,
            $bookingFieldFocus,
            $bookingFieldsComplete,
        );

        return [
            'readiness_score' => $readiness['score'],
            'readiness_label' => $readiness['label'],
            'strong_signals' => $readiness['signals'],
            'cta_level' => $cta['cta_level'],
            'answer_priority' => $cta['answer_priority'],
            'next_best_action' => $cta['next_best_action'],
            'booking_field_focus' => $cta['booking_field_focus'],
            'payment_topic' => $paymentTopic,
            'canonical_intent' => $canonicalIntent,
            'suggested_cta_style' => $cta['suggested_cta_style'],
        ];
    }

    public function toContextBlock(
        Conversation $conversation,
        Lead $lead,
        ?ClassifierOutput $classifier = null,
        ?InterpretationResult $interpretation = null,
    ): string {
        $policy = $this->resolve($conversation, $lead, $classifier, $interpretation);

        $signals = $policy['strong_signals'] === []
            ? '(none)'
            : implode(', ', $policy['strong_signals']);

        return "[CLOSING POLICY]\n"
            . "- readiness_score: {$policy['readiness_score']}\n"
            . "- readiness_label: {$policy['readiness_label']}\n"
            . "- strong_signals: {$signals}\n"
            . "- cta_level: {$policy['cta_level']}\n"
            . "- answer_priority: {$policy['answer_priority']}\n"
            . "- next_best_action: {$policy['next_best_action']}\n"
            . "- booking_field_focus: " . ($policy['booking_field_focus'] ?? '(none)') . "\n"
            . "- payment_topic: " . ($policy['payment_topic'] ?? '(none)') . "\n"
            . "- policy_rule: Jawab pertanyaan user terakhir dulu, lalu beri SATU CTA sesuai cta_level. Jangan lebih agresif dari policy.\n"
            . "- suggested_cta_style: {$policy['suggested_cta_style']}";
    }

    private function canonicalIntentForClassifier(?string $intent): ?string
    {
        return match ($intent) {
            'greeting' => 'greeting',
            'tanya_harga' => 'price_inquiry',
            'tanya_paket', 'bandingkan_paket' => 'package_inquiry',
            'availability' => 'availability_inquiry',
            'payment_inquiry', 'payment_proof' => 'payment_inquiry',
            'ready_to_book' => 'booking_intent',
            'complaint' => 'objection',
            'other', null => null,
            default => 'unclear',
        };
    }

    private function resolveLeadTemperature(Lead $lead): string
    {
        return match ($lead->status->value) {
            'qualified', 'interested' => 'warm',
            'hot', 'ready_for_human', 'closed_won' => 'hot',
            default => 'cold',
        };
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
