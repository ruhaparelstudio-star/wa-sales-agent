<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\DTOs\ToneProfileDto;
use Illuminate\Support\Str;
use Throwable;

class ContextAssembler
{
    public const RECENT_MESSAGES_MAX = 6;
    public const KNOWLEDGE_MAX       = 3;
    public const KNOWLEDGE_CHAR_CAP  = 300;
    public const TOTAL_CHAR_BUDGET   = 8000; // ~2000 tokens (4 chars ≈ 1 token heuristic)

    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly LeadMemoryService $leadMemoryService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly ConversationService $conversationService,
        private readonly ConversationSummaryService $conversationSummaryService,
        private readonly LeadBookingDataService $leadBookingDataService,
        private readonly ConversationStageService $conversationStageService,
        private readonly ConversationStateService $conversationStateService,
        private readonly ClosingPolicyService $closingPolicyService,
        private readonly ResponsePlannerService $responsePlannerService,
    ) {}

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function assemble(Lead $lead, Conversation $conv, LlmMode $mode, string $intent = ''): array
    {
        $tenant = $lead->tenant;

        $toneProfile  = ToneProfileDto::fromTenant($tenant);
        $stage        = $conv->stageEnum();
        $systemPrompt = $this->promptBuilder->buildSystem($tenant, $mode, $toneProfile, $stage);
        $contextBlock = $this->buildContextBlock($lead, $conv, $mode, $intent, $toneProfile);

        // Enforce character budget on the user-context block to keep input bounded.
        if (strlen($contextBlock) > self::TOTAL_CHAR_BUDGET) {
            $contextBlock = substr($contextBlock, 0, self::TOTAL_CHAR_BUDGET) . "\n...[truncated]";
        }

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $contextBlock],
        ];
    }

    public function buildContextBlock(Lead $lead, Conversation $conv, LlmMode $mode, string $intent = '', ?ToneProfileDto $toneProfile = null): string
    {
        $sections = [];

        $toneProfile ??= ToneProfileDto::fromTenant($lead->tenant);
        $latestUserAsk = $this->latestUserAskSection($conv);

        if ($this->includesTenantPolicy($mode)) {
            $sections[] = $this->tenantPolicySection($lead);
        }

        if ($this->includesToneProfile($mode)) {
            $sections[] = $this->toneProfileSection($toneProfile);
        }

        if ($this->includesLeadMemory($mode)) {
            $sections[] = $this->leadMemorySection($lead);
        }

        if ($this->includesStructuredState($mode)) {
            $sections[] = $this->conversationStateSnapshotSection($lead, $conv);
            $sections[] = $this->conversationStateSection($lead, $conv);
        }

        if ($this->includesClosingPolicy($mode)) {
            $sections[] = $this->closingPolicySection($lead, $conv);
        }

        if ($this->includesResponsePlan($mode)) {
            $sections[] = $this->responsePlanSection($lead, $conv);
        }

        if ($this->includesActiveUserFocus($mode)) {
            $activeFocus = $this->activeUserFocusSection($conv);
            if ($activeFocus !== null) {
                $sections[] = $activeFocus;
            }
        }

        if ($latestUserAsk !== null) {
            $sections[] = $latestUserAsk;
        }

        if ($this->includesBookingFields($mode)) {
            $booking = $this->missingBookingFieldsSection($lead);
            if ($booking !== null) {
                $sections[] = $booking;
            }
        }

        if ($this->includesKnowledge($mode)) {
            $sections[] = $this->knowledgeSection($lead, $intent);
        }

        $sections[] = $this->recentMessagesSection($conv);

        if ($this->includesSummary($mode)) {
            $sections[] = $this->summarySection($conv);
        }

        $sections[] = $this->taskSection($mode);

        return implode("\n\n", array_filter($sections));
    }

    private function conversationStateSnapshotSection(Lead $lead, Conversation $conv): string
    {
        return $this->conversationStateService->toContextBlock($conv, $lead);
    }

    private function tenantPolicySection(Lead $lead): string
    {
        $settings = $lead->tenant?->settings ?? [];

        $quietStart = $settings['quiet_hours_start'] ?? '22:00';
        $quietEnd   = $settings['quiet_hours_end']   ?? '07:00';

        $fuCount = (int) ($lead->memory?->custom_fields['follow_up_count'] ?? 0);
        $fuMax   = (int) ($settings['follow_up_max'] ?? 2);
        $paused  = $lead->automation_paused ? 'true' : 'false';

        return "[TENANT POLICY]\n"
            . "- quiet_hours: {$quietStart}-{$quietEnd}\n"
            . "- follow_up_count_used: {$fuCount}/{$fuMax}\n"
            . "- automation_paused: {$paused}";
    }

    private function toneProfileSection(ToneProfileDto $tone): string
    {
        $forbidden = $tone->forbiddenPhrases === []
            ? '(none)'
            : '"' . implode('", "', $tone->forbiddenPhrases) . '"';

        return "[TONE PROFILE]\n"
            . "- language_primary: {$tone->languagePrimary}\n"
            . "- formality: {$tone->formality->value}\n"
            . "- persona_style: {$tone->personaStyle->value}\n"
            . "- forbidden_phrases: {$forbidden}";
    }

    private function conversationStateSection(Lead $lead, Conversation $conv): string
    {
        $state        = $this->conversationStageService->currentState($conv);
        $snapshot     = $this->leadMemoryService->getSnapshot($lead);
        $missing      = $this->conversationStageService->missingDiscoveryFields($conv, $snapshot);
        $nextExpected = $state->nextExpectedField
            ?? $this->conversationStageService->nextExpectedField($conv, $snapshot);

        $askedList   = $state->askedFields === [] ? '(none)' : implode(', ', $state->askedFields);
        $missingList = $missing === [] ? '(none)' : implode(', ', $missing);
        $nextLine    = $nextExpected !== null && $nextExpected !== '' ? $nextExpected : '(none)';

        return "[CONVERSATION STATE]\n"
            . "- stage: {$state->stage->value}\n"
            . "- already_asked: {$askedList}\n"
            . "- still_missing: {$missingList}\n"
            . "- next_expected_field: {$nextLine}\n"
            . "- playbook: Jangan tanya ulang field di already_asked. Fokus kumpulkan next_expected_field. "
            . "Patuhi batas stage saat ini — jangan lompat ke tahap berikutnya sebelum informasi cukup.";
    }

    private function closingPolicySection(Lead $lead, Conversation $conv): string
    {
        return $this->closingPolicyService->toContextBlock($conv, $lead);
    }

    private function responsePlanSection(Lead $lead, Conversation $conv): string
    {
        return $this->responsePlannerService->toContextBlock($conv, $lead);
    }

    private function leadMemorySection(Lead $lead): string
    {
        $snap = $this->leadMemoryService->getSnapshot($lead);
        $tenant = $lead->tenant?->loadMissing('primaryServiceCatalog');

        $lines = [];
        $lines[] = 'name: '           . ($snap['name']           ?? 'null');
        $lines[] = 'event_date: '     . ($snap['event_date']     ?? 'null');
        $lines[] = 'location: '       . ($snap['event_location'] ?? 'null');

        $budget = null;
        if (isset($snap['budget_min']) || isset($snap['budget_max'])) {
            $budget = trim(($snap['budget_min'] ?? '') . '-' . ($snap['budget_max'] ?? ''), '-');
        }
        $lines[] = 'budget: '         . ($budget                 ?: 'null');
        $lines[] = 'service_type: '   . ($snap['service_type']   ?? 'null');
        $lines[] = 'tenant_primary_service_name: ' . ($tenant?->primaryServiceName() ?? 'null');
        $lines[] = 'guest_count: '    . ($snap['guest_count']    ?? 'null');
        $lines[] = 'lead_stage: '     . $lead->status->value;
        $lines[] = 'risk_score: '     . (int) $lead->risk_score;

        return "[LEAD MEMORY]\n- " . implode("\n- ", $lines);
    }

    private function missingBookingFieldsSection(Lead $lead): ?string
    {
        try {
            $preferred = in_array($lead->status, [LeadStatus::Hot, LeadStatus::ReadyForHuman], true)
                ? [FormType::Booking, FormType::Inquiry]
                : [FormType::Inquiry, FormType::Booking];

            foreach ($preferred as $type) {
                $missing = $this->leadBookingDataService->getMissingRequired($lead, $type);

                if (! empty($missing)) {
                    return "[BOOKING FIELDS MISSING]\n"
                        . "- form_type: {$type->value}\n"
                        . "- " . implode("\n- ", $missing);
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function knowledgeSection(Lead $lead, string $intent): string
    {
        $items = $this->knowledgeRetrievalService->getRelevantSubset(
            $lead->tenant,
            $intent,
            self::KNOWLEDGE_MAX,
        );

        if ($items->isEmpty()) {
            return "[KNOWLEDGE]\n- (no knowledge items configured)";
        }

        $lines = $items->map(function ($item) {
            $title = $item->title ?? ($item->type->value ?? 'item');
            $body  = Str::limit((string) ($item->content ?? ''), self::KNOWLEDGE_CHAR_CAP, '');
            return "- {$title}: {$body}";
        })->implode("\n");

        return "[KNOWLEDGE]\n{$lines}";
    }

    private function recentMessagesSection(Conversation $conv): string
    {
        $messages = $this->conversationService->getRecentMessages($conv, self::RECENT_MESSAGES_MAX);

        if ($messages->isEmpty()) {
            return "[RECENT MESSAGES]\n(no messages yet)";
        }

        $lines = $messages->map(function ($msg) {
            $role = $msg->direction->value === 'inbound' ? 'user' : 'assistant';
            return "{$role}: " . (string) $msg->content;
        })->implode("\n");

        return "[RECENT MESSAGES]\n{$lines}";
    }

    private function summarySection(Conversation $conv): string
    {
        $summary = $this->conversationSummaryService->getSummary($conv);
        $text = $summary ? Str::limit($summary, 600, '') : '(no summary yet)';
        return "[CONVERSATION SUMMARY]\n{$text}";
    }

    private function latestUserAskSection(Conversation $conv): ?string
    {
        $message = $conv->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->value('content');

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return "[LATEST USER ASK]\n" . trim($message);
    }

    private function activeUserFocusSection(Conversation $conv): ?string
    {
        $state = $conv->state()->first();
        if ($state === null) {
            return null;
        }

        $filledSlots = is_array($state->filled_slots) ? $state->filled_slots : [];
        $pricingFocus = $filledSlots['pricing_focus'] ?? null;

        if (! is_string($pricingFocus) || $pricingFocus === '') {
            return null;
        }

        $instruction = match ($pricingFocus) {
            'price_only' => 'Jawab fokus harga user dulu. Jangan ulang pertanyaan memilih antara harga vs isi paket.',
            'package_only' => 'Jawab fokus isi paket user dulu. Jangan ulang pertanyaan memilih antara harga vs isi paket.',
            default => 'Jawab fokus harga dan isi paket user dulu. Jangan ulang pertanyaan memilih antara harga vs isi paket.',
        };

        return "[ACTIVE USER FOCUS]\n"
            . "- pricing_focus: {$pricingFocus}\n"
            . "- instruction: {$instruction}";
    }

    private function taskSection(LlmMode $mode): string
    {
        return match ($mode) {
            LlmMode::Classifier => "[TASK]\nClassify pesan user terakhir di LATEST USER ASK dengan bantuan STRUCTURED STATE dan RECENT MESSAGES. Kembalikan JSON saja sesuai schema.",
            LlmMode::Response   => "[TASK]\nJawab LATEST USER ASK dulu. Gunakan RESPONSE PLAN sebagai outline eksekusi. Jika ACTIVE USER FOCUS ada, jawab fokus itu dulu sebelum menggali hal lain. Gunakan state dan CLOSING POLICY, jangan ulang slot yang sudah terisi, lalu beri CTA singkat jika memang sesuai stage.",
            LlmMode::FollowUp   => "[TASK]\nTulis follow-up singkat yang nyambung dengan CONVERSATION SUMMARY, next_best_action, dan CLOSING POLICY. Jangan kirim sapaan generik atau reset discovery.",
            LlmMode::Summary    => "[TASK]\nBuat ringkasan percakapan sesuai format yang diminta.",
            LlmMode::Evaluation => "[TASK]\nEvaluasi kualitas respons terakhir terhadap state dan pesan user terbaru. Kembalikan JSON saja.",
        };
    }

    private function includesTenantPolicy(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp], true);
    }

    private function includesToneProfile(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp], true);
    }

    private function includesLeadMemory(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp, LlmMode::Summary], true);
    }

    private function includesStructuredState(LlmMode $mode): bool
    {
        return in_array($mode, [
            LlmMode::Classifier,
            LlmMode::Response,
            LlmMode::FollowUp,
            LlmMode::Summary,
            LlmMode::Evaluation,
        ], true);
    }

    private function includesBookingFields(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp], true);
    }

    private function includesActiveUserFocus(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp, LlmMode::Evaluation], true);
    }

    private function includesResponsePlan(LlmMode $mode): bool
    {
        return $mode === LlmMode::Response;
    }

    private function includesKnowledge(LlmMode $mode): bool
    {
        return $mode === LlmMode::Response;
    }

    private function includesClosingPolicy(LlmMode $mode): bool
    {
        return in_array($mode, [LlmMode::Response, LlmMode::FollowUp, LlmMode::Evaluation], true);
    }

    private function includesSummary(LlmMode $mode): bool
    {
        return $mode !== LlmMode::Classifier;
    }
}
