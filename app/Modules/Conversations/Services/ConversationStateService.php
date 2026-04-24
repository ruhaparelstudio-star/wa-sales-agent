<?php

namespace App\Modules\Conversations\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use Illuminate\Support\Str;

class ConversationStateService
{
    public function __construct(
        private readonly LeadMemoryService $leadMemoryService,
        private readonly LeadBookingDataService $leadBookingDataService,
        private readonly ConversationStageService $conversationStageService,
        private readonly ClosingPolicyService $closingPolicyService,
    ) {}

    public function loadOrCreate(Conversation $conversation, Lead $lead): ConversationState
    {
        $state = ConversationState::query()->firstOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'tenant_id' => $conversation->tenant_id,
                'lead_id' => $lead->id,
                'current_stage' => $conversation->stageEnum()->value,
                'lead_temperature' => $this->resolveLeadTemperature($lead),
                'filled_slots' => $this->resolveFilledSlots($lead),
                'unresolved_questions' => $this->resolveUnresolvedQuestions($conversation, $lead),
                'next_best_action' => $this->resolveNextBestAction($conversation, $lead),
            ],
        );

        return tap($state, function (ConversationState $state) use ($conversation, $lead): void {
            $this->syncSnapshot($state, $conversation, $lead);
        });
    }

    public function recordInboundMessage(Message $message): ConversationState
    {
        $conversation = $message->conversation()->firstOrFail();
        $lead = $message->lead()->firstOrFail();

        $state = $this->loadOrCreate($conversation, $lead);

        // next_best_action is decided later in the turn (after classifier/interpretation)
        // and committed by applyClassifierResult / recordOutboundMessage. Touching it here
        // would just stamp the previous turn's default and churn the column mid-turn.
        $state->update([
            'last_user_message' => $message->content,
        ]);

        return $state->fresh();
    }

    public function applyInterpretationResult(
        Conversation $conversation,
        Lead $lead,
        InterpretationResult $interpretation,
        ?ClassifierOutput $classifier = null,
    ): ConversationState {
        $state = $this->loadOrCreate($conversation, $lead);
        $unresolved = $this->resolveUnresolvedQuestions($conversation, $lead, $classifier);
        $answeredAskedField = $this->resolveAnsweredAskedField($state, $interpretation->slots);

        $payload = [
            'current_stage' => $conversation->stageEnum()->value,
            'current_intent' => $interpretation->canonicalIntent,
            'intent_confidence' => round($interpretation->confidence, 2),
            'interpretation_source' => $interpretation->source,
            'lead_temperature' => $this->resolveLeadTemperature($lead),
            'filled_slots' => $this->resolveFilledSlots($lead, $state, $interpretation->slots),
            'unresolved_questions' => $unresolved,
            'next_best_action' => $this->resolveNextBestAction($conversation, $lead, $classifier, $unresolved, $interpretation),
        ];

        if ($answeredAskedField !== null) {
            $payload['last_agent_question'] = null;
            $payload['last_answered_topic'] = $answeredAskedField;
        }

        $state->update($payload);
        $this->syncConversationCollectionMetadata($conversation, $lead);

        return $state->fresh();
    }

    public function applyClassifierResult(
        Conversation $conversation,
        Lead $lead,
        ClassifierOutput $classifier,
    ): ConversationState {
        $state = $this->loadOrCreate($conversation, $lead);

        $currentIntent = $this->canonicalIntentForState($classifier->intent, $state->current_intent);
        $unresolved = $this->resolveUnresolvedQuestions($conversation, $lead, $classifier);

        $state->update([
            'current_stage' => $conversation->stageEnum()->value,
            'current_intent' => $currentIntent,
            'intent_confidence' => round($classifier->confidence, 2),
            'interpretation_source' => 'llm',
            'lead_temperature' => $this->resolveLeadTemperature($lead),
            'filled_slots' => $this->resolveFilledSlots($lead, $state, $this->mapClassifierFieldsToSlots($classifier->extractedFields)),
            'unresolved_questions' => $unresolved,
            'next_best_action' => $this->resolveNextBestAction($conversation, $lead, $classifier, $unresolved),
        ]);
        $this->syncConversationCollectionMetadata($conversation, $lead);

        return $state->fresh();
    }

    private function canonicalIntentForState(?string $intent, ?string $fallback): ?string
    {
        $normalized = trim((string) $intent);

        $canonical = match ($normalized) {
            'greeting' => 'greeting',
            'tanya_harga' => 'price_inquiry',
            'tanya_paket', 'bandingkan_paket', 'package_recommendation' => 'package_inquiry',
            'availability' => 'availability_inquiry',
            'payment_inquiry', 'payment_proof' => 'payment_inquiry',
            'ready_to_book' => 'booking_intent',
            'complaint' => 'objection',
            'opt_out' => 'opt_out',
            'other' => 'unclear',
            '' => null,
            default => 'unclear',
        };

        return $canonical ?? $fallback;
    }

    public function recordOutboundMessage(
        Conversation $conversation,
        Lead $lead,
        string $message,
        ?string $nextBestAction = null,
        ?string $toolResultSummary = null,
    ): ConversationState {
        $state = $this->loadOrCreate($conversation, $lead);

        $payload = [
            'last_agent_message' => $message,
            'last_agent_question' => $this->extractQuestion($message),
            'last_answered_topic' => $this->resolveLastAnsweredTopicFromOutbound(
                $message,
                $nextBestAction,
                $toolResultSummary,
                $state,
            ),
            'next_best_action' => $nextBestAction ?? $this->resolveNextBestAction($conversation, $lead),
        ];

        if ($toolResultSummary !== null && trim($toolResultSummary) !== '') {
            $payload['last_tool_result_summary'] = $toolResultSummary;
        }

        $state->update($payload);

        return $state->fresh();
    }

    public function recordToolResult(
        Conversation $conversation,
        Lead $lead,
        string $summary,
        ?string $nextBestAction = null,
    ): ConversationState {
        $state = $this->loadOrCreate($conversation, $lead);

        $state->update([
            'last_tool_result_summary' => $summary,
            'next_best_action' => $nextBestAction ?? $this->resolveNextBestAction($conversation, $lead),
        ]);

        return $state->fresh();
    }

    public function toContextBlock(Conversation $conversation, Lead $lead): string
    {
        $state = $this->loadOrCreate($conversation, $lead);

        $filled = collect($state->filled_slots ?? [])
            ->map(function (mixed $value, string $key): string {
                return $key . ': ' . $this->stringify($value);
            })
            ->implode("\n- ");

        $unresolved = $state->unresolved_questions ?? [];
        $unresolvedList = $unresolved === [] ? '(none)' : implode(', ', $unresolved);

        return "[STRUCTURED STATE]\n"
            . "- current_stage: " . ($state->current_stage ?? '(none)') . "\n"
            . "- current_intent: " . ($state->current_intent ?? '(none)') . "\n"
            . "- intent_confidence: " . ($state->intent_confidence !== null ? number_format((float) $state->intent_confidence, 2, '.', '') : '(none)') . "\n"
            . "- interpretation_source: " . ($state->interpretation_source ?? '(none)') . "\n"
            . "- lead_temperature: " . ($state->lead_temperature ?? 'cold') . "\n"
            . "- filled_slots:\n- " . ($filled !== '' ? $filled : '(none)') . "\n"
            . "- unresolved_questions: {$unresolvedList}\n"
            . "- last_agent_question: " . ($state->last_agent_question ?? '(none)') . "\n"
            . "- last_answered_topic: " . ($state->last_answered_topic ?? '(none)') . "\n"
            . "- next_best_action: " . ($state->next_best_action ?? '(none)') . "\n"
            . "- last_tool_result_summary: " . ($state->last_tool_result_summary ?? '(none)');
    }

    private function syncSnapshot(ConversationState $state, Conversation $conversation, Lead $lead): void
    {
        $updates = [
            'tenant_id' => $conversation->tenant_id,
            'lead_id' => $lead->id,
            'current_stage' => $conversation->stageEnum()->value,
            'lead_temperature' => $this->resolveLeadTemperature($lead),
            'filled_slots' => $this->resolveFilledSlots($lead, $state),
            'unresolved_questions' => $this->resolveUnresolvedQuestions($conversation, $lead),
        ];

        if ($this->needsSync($state, $updates)) {
            $state->update($updates);
        }

        $this->syncConversationCollectionMetadata($conversation, $lead);
    }

    private function needsSync(ConversationState $state, array $updates): bool
    {
        foreach ($updates as $key => $value) {
            if ($state->{$key} !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep conversation-level collection metadata aligned with the durable snapshot
     * so prompt/context layers do not keep stale discovery targets around.
     *
     * Only writes `next_expected_field` now. `asked_fields` is owned exclusively by
     * RecordAskedFieldAction, which appends a field only after the outbound message
     * actually contains that question.
     */
    private function syncConversationCollectionMetadata(
        Conversation $conversation,
        Lead $lead,
    ): void {
        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        // asked_fields is append-only and owned by RecordAskedFieldAction. Previously we
        // auto-merged resolved (already-filled) fields here to stop re-asking, but that
        // leaked false-positive slot extractions into asked_fields. missingDiscoveryFields
        // already skips fields that are filled in the lead snapshot, so the merge was
        // redundant — and harmful when slot extraction made a bad guess.
        $askedFields = $conversation->askedFields();

        $nextExpectedField = $this->computedNextExpectedField($conversation, $snapshot, $askedFields);

        if ($conversation->next_expected_field === $nextExpectedField) {
            return;
        }

        $conversation->forceFill([
            'next_expected_field' => $nextExpectedField,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $askedFields
     */
    private function computedNextExpectedField(
        Conversation $conversation,
        array $snapshot,
        array $askedFields,
    ): ?string {
        if (! in_array($conversation->stageEnum(), [
            ConversationStage::NewLead,
            ConversationStage::Qualification,
            ConversationStage::NeedsDiscovery,
        ], true)) {
            return null;
        }

        $probe = clone $conversation;
        $probe->asked_fields = $askedFields;

        return $this->conversationStageService->nextExpectedField($probe, $snapshot);
    }

    /**
     * @param  array<string, mixed>  $interpretedSlots
     * @return array<string, mixed>
     */
    private function resolveFilledSlots(Lead $lead, ?ConversationState $state = null, array $interpretedSlots = []): array
    {
        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        $budget = null;
        $existing = is_array($state?->filled_slots) ? $state->filled_slots : [];

        if (isset($snapshot['budget_min']) || isset($snapshot['budget_max'])) {
            $budget = trim(($snapshot['budget_min'] ?? '') . '-' . ($snapshot['budget_max'] ?? ''), '-');
        }

        return [
            'event_type' => $this->normalizeEventTypeValue($interpretedSlots['event_type'] ?? $snapshot['service_type'] ?? $existing['event_type'] ?? null),
            'name' => $snapshot['name'] ?? $existing['name'] ?? null,
            'event_date' => $interpretedSlots['event_date'] ?? $snapshot['event_date'] ?? $existing['event_date'] ?? null,
            'event_time_start' => $interpretedSlots['event_time_start'] ?? $snapshot['event_time_start'] ?? $existing['event_time_start'] ?? null,
            'event_time_end' => $interpretedSlots['event_time_end'] ?? $snapshot['event_time_end'] ?? $existing['event_time_end'] ?? null,
            'location' => $interpretedSlots['location'] ?? $snapshot['event_location'] ?? $existing['location'] ?? null,
            'service_type' => $this->normalizeEventTypeValue($interpretedSlots['event_type'] ?? $snapshot['service_type'] ?? $existing['service_type'] ?? null),
            'guest_count' => $snapshot['guest_count'] ?? $existing['guest_count'] ?? null,
            'budget' => $interpretedSlots['budget'] ?? ($budget !== '' ? $budget : ($existing['budget'] ?? null)),
            'pricing_focus' => $interpretedSlots['pricing_focus'] ?? $snapshot['pricing_focus'] ?? $existing['pricing_focus'] ?? null,
            'package_interest' => $this->normalizePackageInterestValue($interpretedSlots['package_interest'] ?? $snapshot['package_interest'] ?? $existing['package_interest'] ?? null),
            'payment_topic' => $interpretedSlots['payment_topic'] ?? $snapshot['payment_topic'] ?? $this->inferPaymentTopic($lead) ?? $existing['payment_topic'] ?? null,
            'inquiry_fields' => $this->leadBookingDataService->getForLead($lead, FormType::Inquiry),
            'booking_fields' => $this->leadBookingDataService->getForLead($lead, FormType::Booking),
        ];
    }

    private function inferPaymentTopic(Lead $lead): ?string
    {
        $pendingPaymentProof = $lead->handoffRequests()
            ->pending()
            ->where('reason', 'payment_proof')
            ->exists();

        return $pendingPaymentProof ? 'payment_proof' : null;
    }

    private function resolveUnresolvedQuestions(
        Conversation $conversation,
        Lead $lead,
        ?ClassifierOutput $classifier = null,
    ): array {
        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        $questions = [];

        $questions = array_merge(
            $questions,
            $this->conversationStageService->missingDiscoveryFields($conversation, $snapshot),
        );

        if (
            in_array($lead->status, [LeadStatus::Hot, LeadStatus::ReadyForHuman], true)
            || in_array($conversation->stageEnum(), [
                ConversationStage::PaymentDiscussion,
                ConversationStage::Closing,
                ConversationStage::HandoffToHuman,
            ], true)
        ) {
            $questions = array_merge(
                $questions,
                $this->leadBookingDataService->getMissingRequired($lead, FormType::Booking),
            );
        }

        if ($classifier !== null) {
            $questions = array_merge($questions, $classifier->missingCriticalFields);
        }

        return array_values(array_unique(array_filter(array_map('strval', $questions))));
    }

    private function resolveLeadTemperature(Lead $lead): string
    {
        return match ($lead->status) {
            LeadStatus::New, LeadStatus::ClosedLost => 'cold',
            LeadStatus::Qualified, LeadStatus::Interested => 'warm',
            LeadStatus::Hot, LeadStatus::ReadyForHuman, LeadStatus::ClosedWon => 'hot',
        };
    }

    private function resolveLastAnsweredTopicFromOutbound(
        string $message,
        ?string $nextBestAction,
        ?string $toolResultSummary,
        ConversationState $state,
    ): ?string
    {
        $normalizedMessage = Str::lower(trim($message));
        $normalizedAction = Str::lower(trim((string) $nextBestAction));
        $normalizedToolSummary = Str::lower(trim((string) $toolResultSummary));

        if ($normalizedToolSummary !== '' && str_contains($normalizedToolSummary, 'pricelist')) {
            return 'pricing';
        }

        if ($normalizedAction === 'share_pricelist') {
            return 'pricing';
        }

        foreach (['ask_', 'collect_'] as $prefix) {
            if (str_starts_with($normalizedAction, $prefix)) {
                $field = substr($normalizedAction, strlen($prefix));

                return $field !== '' ? $field : $state->last_answered_topic;
            }
        }

        if (in_array($normalizedAction, ['guide_to_booking', 'confirm_booking_step', 'collect_booking_fields'], true)) {
            return 'booking';
        }

        if (str_starts_with($normalizedAction, 'answer_payment')) {
            return 'payment';
        }

        if ($this->containsAny($normalizedMessage, ['pricelist', 'price list', 'daftar harga', 'harga', 'isi paket', 'paket'])) {
            return 'pricing';
        }

        if ($this->containsAny($normalizedMessage, ['pembayaran', 'transfer', 'rekening', 'dp', 'pelunasan', 'bukti bayar', 'bukti transfer'])) {
            return 'payment';
        }

        if ($this->containsAny($normalizedMessage, ['booking', 'book', 'data booking', 'lanjut booking'])) {
            return 'booking';
        }

        if ($this->containsAny($normalizedMessage, ['tanggal tersedia', 'cek ketersediaan', 'ketersediaan', 'availability'])) {
            return 'availability';
        }

        if ($this->containsAny($normalizedMessage, ['mohon maaf', 'ketidaknyamanan', 'ragu', 'concern'])) {
            return 'complaint';
        }

        return $state->last_answered_topic;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $unresolved
     */
    private function resolveNextBestAction(
        Conversation $conversation,
        Lead $lead,
        ?ClassifierOutput $classifier = null,
        ?array $unresolved = null,
        ?InterpretationResult $interpretation = null,
    ): string {
        if ($conversation->isHandoff() || $conversation->stageEnum() === ConversationStage::HandoffToHuman || $classifier?->needsHandoff) {
            return 'handoff_to_human';
        }

        $unresolved ??= $this->resolveUnresolvedQuestions($conversation, $lead, $classifier);

        $closingPolicy = $this->closingPolicyService->resolve(
            $conversation,
            $lead,
            $classifier,
            $interpretation,
        );
        $leadSnapshot = $this->leadMemoryService->getSnapshot($lead);
        $nextRecommendationField = $this->conversationStageService->nextRecommendationField(
            $conversation,
            $leadSnapshot,
        );

        if (($closingPolicy['cta_level'] ?? 'none') !== 'none') {
            return $closingPolicy['next_best_action'];
        }

        if (in_array($conversation->stageEnum(), [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
        ], true)) {
            $nextBookingField = $this->leadBookingDataService->nextMissingRequiredField($lead, FormType::Booking);
            if ($nextBookingField !== null) {
                return 'collect_' . $nextBookingField->field_key;
            }
        }

        if ($lead->status === LeadStatus::Hot || $lead->status === LeadStatus::ReadyForHuman) {
            $nextBookingField = $this->leadBookingDataService->nextMissingRequiredField($lead, FormType::Booking);
            if ($nextBookingField !== null) {
                return 'collect_' . $nextBookingField->field_key;
            }
        }

        $nextDiscoveryField = $this->conversationStageService->nextExpectedField($conversation, $leadSnapshot);

        if (in_array($conversation->stageEnum(), [
            ConversationStage::NewLead,
            ConversationStage::Qualification,
            ConversationStage::NeedsDiscovery,
        ], true) && $nextDiscoveryField !== null) {
            return 'ask_' . $nextDiscoveryField;
        }

        if ($classifier !== null && in_array($classifier->intent, ['tanya_harga', 'tanya_paket', 'bandingkan_paket'], true)) {
            if ($nextRecommendationField !== null) {
                return 'ask_' . $nextRecommendationField;
            }

            return empty($unresolved) ? 'share_pricelist' : 'continue_qualification';
        }

        if ($interpretation !== null) {
            return match ($interpretation->canonicalIntent) {
                'booking_intent' => 'guide_to_booking',
                'availability_inquiry' => 'handoff_to_human',
                'payment_inquiry' => 'answer_payment_question',
                'price_inquiry', 'package_inquiry' => $nextRecommendationField !== null
                    ? 'ask_' . $nextRecommendationField
                    : (empty($unresolved) ? 'share_pricelist' : 'continue_qualification'),
                default => $conversation->stageEnum() === ConversationStage::Closing ? 'guide_to_booking' : 'respond_to_user',
            };
        }

        if (in_array($conversation->stageEnum(), [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
        ], true)) {
            return 'guide_to_booking';
        }

        return 'respond_to_user';
    }

    private function extractQuestion(string $message): ?string
    {
        $trimmed = trim($message);

        if ($trimmed === '' || ! str_contains($trimmed, '?')) {
            return null;
        }

        $segments = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [$trimmed];
        $questions = array_values(array_filter($segments, static fn (string $segment): bool => str_contains($segment, '?')));

        return $questions === [] ? null : end($questions);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return Str::limit((string) $value, 180, '');
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
                'service_type', 'event_type' => $slots['event_type'] = $this->normalizeEventTypeValue($value),
                'event_date' => $slots['event_date'] = $value,
                'location' => $slots['location'] = $value,
                'budget' => $slots['budget'] = is_scalar($value) ? (string) $value : null,
                'package_interest' => $slots['package_interest'] = $this->normalizePackageInterestValue($value),
                default => null,
            };
        }

        return array_filter($slots, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function normalizeEventTypeValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'lamaran'), str_contains($normalized, 'engagement') => 'engagement',
            str_contains($normalized, 'prewedding'), str_contains($normalized, 'pre wedding') => 'prewedding',
            str_contains($normalized, 'wedding'), str_contains($normalized, 'akad'), str_contains($normalized, 'resepsi') => 'wedding',
            default => trim((string) $value),
        };
    }

    private function normalizePackageInterestValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'photo') && str_contains($normalized, 'video') => 'photo + video',
            str_contains($normalized, 'foto') && str_contains($normalized, 'video') => 'photo + video',
            str_contains($normalized, 'photo') && str_contains($normalized, 'album') => 'photo + album',
            str_contains($normalized, 'foto') && str_contains($normalized, 'album') => 'photo + album',
            str_contains($normalized, 'photo'), str_contains($normalized, 'foto') => 'photo only',
            str_contains($normalized, 'video') => 'video only',
            default => trim((string) $value),
        };
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function resolveAnsweredAskedField(ConversationState $state, array $slots): ?string
    {
        $lastAgentQuestion = trim((string) ($state->last_agent_question ?? ''));
        if ($lastAgentQuestion === '') {
            return null;
        }

        $fieldPatterns = [
            'service_type' => [
                'layanan yang dicari',
                'layanan yang kamu cari',
                'layanan yang kakak cari',
                'fokus foto',
                'foto, video, atau keduanya',
                'foto, video atau keduanya',
                'foto atau video',
            ],
            'event_date' => [
                'tanggal acara',
                'tanggalnya',
                'tanggal berapa',
                'tanggal kapan',
            ],
            'location' => [
                'lokasi acara',
                'lokasinya',
                'di kota mana',
                'di mana acaranya',
                'dimana acaranya',
            ],
            'guest_count' => [
                'jumlah tamu',
                'perkiraan jumlah tamu',
                'berapa tamu',
            ],
            'budget' => [
                'budget yang disiapkan',
                'budgetnya',
                'anggaran yang disiapkan',
                'budget',
                'anggaran',
            ],
            'name' => [
                'nama lengkap',
                'namanya siapa',
                'siapa nama',
            ],
        ];

        $normalizedQuestion = Str::lower($lastAgentQuestion);

        foreach ($fieldPatterns as $field => $patterns) {
            if (! array_key_exists($field, $slots) || blank($slots[$field])) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($normalizedQuestion, $pattern)) {
                    return $field;
                }
            }
        }

        return null;
    }
}
