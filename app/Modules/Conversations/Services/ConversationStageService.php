<?php

namespace App\Modules\Conversations\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\DTOs\ConversationStateDto;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;

class ConversationStageService
{
    /**
     * Qualification fields are the minimum details we need before moving from
     * opening discovery into package guidance.
     *
     * @var list<string>
     */
    private const QUALIFICATION_FIELDS = [
        'event_date',
        'location',
    ];

    /**
     * Secondary discovery fields enrich the match after core qualification is clear.
     *
     * @var list<string>
     */
    private const DISCOVERY_FIELDS = [
        'guest_count',
        'budget',
        'name',
    ];

    /**
     * Basic client information required before the agent should recommend a package.
     *
     * @var list<string>
     */
    private const RECOMMENDATION_FIELDS = [
        'event_date',
        'location',
        'guest_count',
        'budget',
    ];

    public function __construct(
        private readonly TransitionConversationStageAction $transitionAction,
    ) {}

    public function currentState(Conversation $conversation): ConversationStateDto
    {
        return new ConversationStateDto(
            stage:             $conversation->stageEnum(),
            askedFields:       $conversation->askedFields(),
            nextExpectedField: $conversation->next_expected_field,
            transitionCount:   (int) ($conversation->stage_transition_count ?? 0),
        );
    }

    /**
     * Runtime stage transitions are owned by this service.
     *
     * Classifier/interpretation stage suggestions are advisory only; the effective
     * transition is derived here from the final intent plus the durable memory snapshot.
     * Explicit hard branches such as handoff and direct-pricelist promotion stay outside
     * this rule path.
     *
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    public function decideAndApply(
        Conversation $conversation,
        ClassifierOutput $classifier,
        array $leadMemorySnapshot = [],
        ?string $latestUserMessage = null,
    ): ConversationStage {
        $current = $conversation->stageEnum();

        if ($classifier->needsHandoff) {
            return $this->transitionAction->execute(
                $conversation,
                ConversationStage::HandoffToHuman,
                'rule',
                'handoff_required:' . ($classifier->handoffReason ?? $classifier->intent),
            );
        }

        $byIntent = $this->inferTargetByIntent($current, $classifier, $leadMemorySnapshot, $latestUserMessage);
        if ($byIntent !== null && $byIntent !== $current && $current->canTransitionTo($byIntent)) {
            return $this->transitionAction->execute(
                $conversation,
                $byIntent,
                'rule',
                'intent_fallback:' . $classifier->intent,
            );
        }

        return $current;
    }

    public function promoteForDirectPricelistInquiry(Conversation $conversation): ConversationStage
    {
        $current = $conversation->stageEnum();

        if (in_array($current, [
            ConversationStage::PackageRecommendation,
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
            ConversationStage::Booked,
            ConversationStage::Closed,
        ], true)) {
            return $current;
        }

        if ($current === ConversationStage::NewLead) {
            $current = $this->transitionAction->execute(
                $conversation,
                ConversationStage::Qualification,
                'rule',
                'direct_pricelist_inquiry_bootstrap',
            );

            $conversation->refresh();
        }

        $current = $conversation->stageEnum();
        if (! $current->canTransitionTo(ConversationStage::PackageRecommendation)) {
            return $current;
        }

        return $this->transitionAction->execute(
            $conversation,
            ConversationStage::PackageRecommendation,
            'rule',
            'direct_pricelist_inquiry',
        );
    }

    /**
     * Return fields still missing for the current collection stage, excluding fields
     * already asked so the agent does not repeat itself.
     *
     * @param  array<string, mixed>  $leadMemorySnapshot
     * @return list<string>
     */
    public function missingDiscoveryFields(Conversation $conversation, array $leadMemorySnapshot): array
    {
        $fields = $this->fieldSequenceForStage($conversation->stageEnum());
        if ($fields === []) {
            return [];
        }

        $asked = $conversation->askedFields();
        $missing = [];

        foreach ($fields as $field) {
            if ($this->snapshotHasValue($field, $leadMemorySnapshot)) {
                continue;
            }

            if (in_array($field, $asked, true)) {
                continue;
            }

            $missing[] = $field;
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    public function nextExpectedField(Conversation $conversation, array $leadMemorySnapshot): ?string
    {
        $missing = $this->missingDiscoveryFields($conversation, $leadMemorySnapshot);

        return $missing[0] ?? null;
    }

    /**
     * Resolve the next field after a specific field has just been asked.
     *
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    public function nextExpectedFieldAfterAsking(
        Conversation $conversation,
        array $leadMemorySnapshot,
        string $askedField,
    ): ?string {
        $askedField = trim($askedField);
        if ($askedField === '') {
            return $this->nextExpectedField($conversation, $leadMemorySnapshot);
        }

        $fields = $this->fieldSequenceForStage($conversation->stageEnum());
        if ($fields === []) {
            return null;
        }

        $asked = $conversation->askedFields();
        if (! in_array($askedField, $asked, true)) {
            $asked[] = $askedField;
        }

        foreach ($fields as $field) {
            if ($this->snapshotHasValue($field, $leadMemorySnapshot)) {
                continue;
            }

            if (in_array($field, $asked, true)) {
                continue;
            }

            return $field;
        }

        return null;
    }

    /**
     * Return client basics still missing before recommendation should be offered.
     *
     * @param  array<string, mixed>  $leadMemorySnapshot
     * @return list<string>
     */
    public function missingRecommendationFields(Conversation $conversation, array $leadMemorySnapshot): array
    {
        $asked = $conversation->askedFields();
        $missing = [];

        foreach (self::RECOMMENDATION_FIELDS as $field) {
            if ($this->snapshotHasValue($field, $leadMemorySnapshot)) {
                continue;
            }

            if (in_array($field, $asked, true)) {
                continue;
            }

            $missing[] = $field;
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    public function nextRecommendationField(Conversation $conversation, array $leadMemorySnapshot): ?string
    {
        $missing = $this->missingRecommendationFields($conversation, $leadMemorySnapshot);

        return $missing[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    private function inferTargetByIntent(
        ConversationStage $current,
        ClassifierOutput $classifier,
        array $leadMemorySnapshot,
        ?string $latestUserMessage = null,
    ): ?ConversationStage {
        if ($current === ConversationStage::Closed) {
            return null;
        }

        if ($current === ConversationStage::NewLead && $classifier->intent === 'greeting') {
            return ConversationStage::Qualification;
        }

        return match (true) {
            in_array($classifier->intent, ['availability', 'custom_package', 'payment_proof', 'opt_out'], true)
                => ConversationStage::HandoffToHuman,
            $classifier->intent === 'payment_inquiry'
                => $this->stageForPaymentIntent($current, $classifier, $leadMemorySnapshot, $latestUserMessage),
            in_array($classifier->intent, ['tanya_harga', 'tanya_paket', 'bandingkan_paket'], true)
                => $this->stageForPricingIntent($current, $leadMemorySnapshot),
            $classifier->intent === 'ready_to_book'
                => $this->stageForBookingIntent($current),
            $classifier->intent === 'complaint'
                => ConversationStage::ObjectionHandling,
            $this->shouldAdvanceToNeedsDiscovery($current, $leadMemorySnapshot)
                => ConversationStage::NeedsDiscovery,
            $this->shouldAdvanceToPackageRecommendation($current, $leadMemorySnapshot)
                => ConversationStage::PackageRecommendation,
            $current === ConversationStage::NewLead
                => ConversationStage::Qualification,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function fieldSequenceForStage(ConversationStage $stage): array
    {
        return match ($stage) {
            ConversationStage::NewLead,
            ConversationStage::Qualification => self::QUALIFICATION_FIELDS,
            ConversationStage::NeedsDiscovery => self::DISCOVERY_FIELDS,
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    private function stageForPricingIntent(
        ConversationStage $current,
        array $leadMemorySnapshot,
    ): ConversationStage {
        if ($current === ConversationStage::PaymentDiscussion) {
            if (! $this->hasAllFields(self::QUALIFICATION_FIELDS, $leadMemorySnapshot)) {
                return ConversationStage::Qualification;
            }

            return ConversationStage::PackageRecommendation;
        }

        if (in_array($current, [ConversationStage::Closing, ConversationStage::Booked], true)) {
            return $current;
        }

        if (! $this->hasAllFields(self::QUALIFICATION_FIELDS, $leadMemorySnapshot)) {
            return ConversationStage::Qualification;
        }

        return ConversationStage::PackageRecommendation;
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    private function stageForPaymentIntent(
        ConversationStage $current,
        ClassifierOutput $classifier,
        array $leadMemorySnapshot,
        ?string $latestUserMessage = null,
    ): ConversationStage {
        if (! $this->hasExplicitPaymentSignal($classifier, $latestUserMessage)) {
            return $this->stageForPricingIntent($current, $leadMemorySnapshot);
        }

        return $current === ConversationStage::Booked
            ? ConversationStage::Booked
            : ConversationStage::PaymentDiscussion;
    }

    private function stageForBookingIntent(ConversationStage $current): ConversationStage
    {
        if ($current === ConversationStage::Booked) {
            return ConversationStage::Booked;
        }

        if ($current->canTransitionTo(ConversationStage::Closing)) {
            return ConversationStage::Closing;
        }

        if ($current->canTransitionTo(ConversationStage::PaymentDiscussion)) {
            return ConversationStage::PaymentDiscussion;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    private function shouldAdvanceToNeedsDiscovery(
        ConversationStage $current,
        array $leadMemorySnapshot,
    ): bool {
        return in_array($current, [ConversationStage::NewLead, ConversationStage::Qualification], true)
            && $this->hasAllFields(self::QUALIFICATION_FIELDS, $leadMemorySnapshot);
    }

    /**
     * @param  array<string, mixed>  $leadMemorySnapshot
     */
    private function shouldAdvanceToPackageRecommendation(
        ConversationStage $current,
        array $leadMemorySnapshot,
    ): bool {
        return $current === ConversationStage::NeedsDiscovery
            && $this->hasAllFields(self::DISCOVERY_FIELDS, $leadMemorySnapshot);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $snapshot
     */
    private function hasAllFields(array $fields, array $snapshot): bool
    {
        foreach ($fields as $field) {
            if (! $this->snapshotHasValue($field, $snapshot)) {
                return false;
            }
        }

        return true;
    }

    private function hasExplicitPaymentSignal(ClassifierOutput $classifier, ?string $latestUserMessage = null): bool
    {
        $paymentTopic = trim((string) ($classifier->extractedFields['payment_topic'] ?? ''));
        if ($paymentTopic !== '') {
            return true;
        }

        $content = mb_strtolower(trim((string) $latestUserMessage));
        if ($content === '') {
            return true;
        }

        return preg_match('/\b(dp|bayar|payment|pelunasan|transfer|invoice)\b/u', $content) === 1
            || str_contains($content, 'booking lanjut')
            || str_contains($content, 'jadi booking')
            || str_contains($content, 'lanjut booking');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasValue(string $field, array $snapshot): bool
    {
        $key = match ($field) {
            'location' => 'event_location',
            'budget'   => 'budget_min',
            default    => $field,
        };

        $value = $snapshot[$key] ?? null;

        return $value !== null && $value !== '';
    }
}
