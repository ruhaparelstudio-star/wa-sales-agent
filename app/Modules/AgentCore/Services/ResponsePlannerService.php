<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;

class ResponsePlannerService
{
    public function __construct(
        private readonly ClosingPolicyService $closingPolicyService,
    ) {}

    /**
     * @return array{
     *   plan_version: string,
     *   answer_mode: string,
     *   answer_focus: string,
     *   ask_mode: string,
     *   ask_field: string|null,
     *   next_best_action: string,
     *   cta_level: string,
     *   cta_style: string,
     *   user_focus_rule: string,
     *   banned_moves: list<string>
     * }
     */
    public function resolve(Conversation $conversation, Lead $lead): array
    {
        $state = $conversation->state()->first();
        $filledSlots = is_array($state?->filled_slots) ? $state->filled_slots : [];
        $currentIntent = (string) ($state?->current_intent ?? 'unclear');
        $nextBestAction = (string) ($state?->next_best_action ?? 'respond_to_user');
        $lastUserMessage = mb_strtolower(trim((string) ($state?->last_user_message ?? '')));
        $pricingFocus = $this->stringOrNull($filledSlots['pricing_focus'] ?? null);
        $packageInterest = $this->stringOrNull($filledSlots['package_interest'] ?? null);
        $recommendationAskField = $this->recommendationAskField($currentIntent, $lastUserMessage, $filledSlots);
        $shouldSuppressDiscoveryProbe = $this->shouldSuppressDiscoveryProbe($currentIntent, $conversation->stageEnum());

        $closingPolicy = $this->closingPolicyService->resolve($conversation, $lead);
        $shouldSuppressPaymentFraming = $this->shouldSuppressPaymentFraming($currentIntent, $conversation->stageEnum());
        $effectiveNextBestAction = ($shouldSuppressPaymentFraming || $shouldSuppressDiscoveryProbe)
            ? 'respond_to_user'
            : $nextBestAction;
        $effectivePaymentTopic = $shouldSuppressPaymentFraming
            ? null
            : ($closingPolicy['payment_topic'] ?? null);
        $askField = $recommendationAskField ?? $this->extractFieldFromAction($effectiveNextBestAction);

        return [
            'plan_version' => 'v1',
            'answer_mode' => $this->answerMode(
                $currentIntent,
                $conversation->stageEnum(),
                $effectiveNextBestAction,
                $pricingFocus,
                $recommendationAskField,
            ),
            'answer_focus' => $this->answerFocus(
                currentIntent: $currentIntent,
                pricingFocus: $pricingFocus,
                packageInterest: $packageInterest,
                paymentTopic: $effectivePaymentTopic,
                bookingFieldFocus: $closingPolicy['booking_field_focus'] ?? null,
                askField: $askField,
                recommendationAskField: $recommendationAskField,
            ),
            'ask_mode' => $this->askMode($effectiveNextBestAction, $recommendationAskField),
            'ask_field' => $askField,
            'next_best_action' => $effectiveNextBestAction,
            'cta_level' => $shouldSuppressPaymentFraming ? 'none' : (string) ($closingPolicy['cta_level'] ?? 'none'),
            'cta_style' => $shouldSuppressPaymentFraming
                ? 'Fokus jawab kebutuhan paket atau harga user dulu tanpa membawa framing payment atau discovery baru.'
                : (string) ($closingPolicy['suggested_cta_style'] ?? 'none'),
            'user_focus_rule' => $this->userFocusRule($pricingFocus, $currentIntent, $recommendationAskField, $shouldSuppressDiscoveryProbe),
            'banned_moves' => $this->bannedMoves($conversation->stageEnum(), $pricingFocus),
        ];
    }

    public function toContextBlock(Conversation $conversation, Lead $lead): string
    {
        $plan = $this->resolve($conversation, $lead);

        return "[RESPONSE PLAN]\n"
            . "- plan_version: {$plan['plan_version']}\n"
            . "- answer_mode: {$plan['answer_mode']}\n"
            . "- answer_focus: {$plan['answer_focus']}\n"
            . "- ask_mode: {$plan['ask_mode']}\n"
            . "- ask_field: " . ($plan['ask_field'] ?? '(none)') . "\n"
            . "- next_best_action: {$plan['next_best_action']}\n"
            . "- cta_level: {$plan['cta_level']}\n"
            . "- cta_style: {$plan['cta_style']}\n"
            . "- user_focus_rule: {$plan['user_focus_rule']}\n"
            . "- banned_moves: " . implode(' | ', $plan['banned_moves']);
    }

    private function answerMode(
        string $currentIntent,
        ConversationStage $stage,
        string $nextBestAction,
        ?string $pricingFocus,
        ?string $recommendationAskField,
    ): string
    {
        if ($stage === ConversationStage::HandoffToHuman || $nextBestAction === 'handoff_to_human') {
            return 'handoff_acknowledgment';
        }

        if ($currentIntent === 'package_inquiry' && $recommendationAskField !== null) {
            return 'recommend_package';
        }

        if ($currentIntent === 'package_inquiry' && $pricingFocus === 'package_only') {
            return 'grounded_package_answer';
        }

        return match ($currentIntent) {
            'price_inquiry', 'package_inquiry' => 'answer_pricing',
            'payment_inquiry' => 'answer_payment',
            'booking_intent' => 'guide_booking',
            'availability_inquiry' => 'availability_routing',
            'objection' => 'handle_objection',
            'follow_up' => 'resume_context',
            default => 'answer_latest_question',
        };
    }

    private function answerFocus(
        string $currentIntent,
        ?string $pricingFocus,
        ?string $packageInterest,
        ?string $paymentTopic,
        ?string $bookingFieldFocus,
        ?string $askField,
        ?string $recommendationAskField,
    ): string {
        if ($recommendationAskField !== null) {
            return 'package_recommendation_then_probe:' . $recommendationAskField;
        }

        if ($pricingFocus !== null) {
            return 'pricing_focus:' . $pricingFocus;
        }

        if ($paymentTopic !== null) {
            return 'payment_topic:' . $paymentTopic;
        }

        if ($bookingFieldFocus !== null) {
            return 'booking_field:' . $bookingFieldFocus;
        }

        if ($packageInterest !== null) {
            return 'package_interest:' . $packageInterest;
        }

        if ($askField !== null) {
            return 'missing_field:' . $askField;
        }

        return match ($currentIntent) {
            'price_inquiry', 'package_inquiry' => 'pricing_general',
            'payment_inquiry' => 'payment_general',
            'booking_intent' => 'booking_general',
            'availability_inquiry' => 'availability_general',
            'objection' => 'objection_general',
            default => 'latest_user_question',
        };
    }

    private function askMode(string $nextBestAction, ?string $recommendationAskField): string
    {
        if ($recommendationAskField !== null) {
            return 'ask_single_missing_field';
        }

        if (str_starts_with($nextBestAction, 'ask_')) {
            return 'ask_single_missing_field';
        }

        if (str_starts_with($nextBestAction, 'collect_')) {
            return 'collect_booking_field';
        }

        return 'no_question_unless_needed';
    }

    private function userFocusRule(
        ?string $pricingFocus,
        string $currentIntent,
        ?string $recommendationAskField,
        bool $shouldSuppressDiscoveryProbe,
    ): string
    {
        return match (true) {
            $recommendationAskField !== null => sprintf(
                'Beri rekomendasi awal yang paling masuk akal dari context, jelaskan singkat alasannya, lalu tanyakan SATU hal paling penting tentang %s.',
                $recommendationAskField,
            ),
            $shouldSuppressDiscoveryProbe => 'Jawab kebutuhan paket atau harga user dulu tanpa minta data discovery baru.',
            $pricingFocus === 'price_only' => 'Jawab harga dulu, jangan suruh user memilih ulang topik pricing.',
            $pricingFocus === 'package_only' => 'Jawab isi paket dulu, jangan suruh user memilih ulang topik pricing.',
            $pricingFocus === 'price_and_package' => 'Jawab harga dan isi paket dulu, jangan ulang triage pricing.',
            $currentIntent === 'payment_inquiry' => 'Jawab pertanyaan payment user dulu sebelum CTA lain.',
            $currentIntent === 'booking_intent' => 'Arahkan ke langkah booking berikutnya tanpa regress ke discovery umum.',
            default => 'Jawab pertanyaan user terbaru dulu sebelum menggali hal lain.',
        };
    }

    /**
     * @return list<string>
     */
    private function bannedMoves(ConversationStage $stage, ?string $pricingFocus): array
    {
        $moves = [
            'jangan reset percakapan dengan pertanyaan generik',
            'jangan tanya ulang slot yang sudah terisi',
            'jangan klaim tool/action yang tidak benar-benar terjadi',
        ];

        if ($pricingFocus !== null) {
            $moves[] = 'jangan ulang triage harga vs isi paket';
        }

        if ($pricingFocus !== null) {
            $moves[] = 'jangan suntik topik DP, pelunasan, atau booking kalau user masih fokus ke paket/harga';
        }

        if (in_array($stage, [ConversationStage::PaymentDiscussion, ConversationStage::Closing, ConversationStage::Booked], true)) {
            $moves[] = 'jangan regress ke discovery umum';
        }

        return $moves;
    }

    private function extractFieldFromAction(string $nextBestAction): ?string
    {
        foreach (['ask_', 'collect_'] as $prefix) {
            if (str_starts_with($nextBestAction, $prefix)) {
                $field = substr($nextBestAction, strlen($prefix));

                return $field !== '' ? $field : null;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param  array<string, mixed>  $filledSlots
     */
    private function recommendationAskField(string $currentIntent, string $lastUserMessage, array $filledSlots): ?string
    {
        if ($currentIntent !== 'package_inquiry' || ! $this->isRecommendationPrompt($lastUserMessage)) {
            return null;
        }

        if ($this->stringOrNull($filledSlots['budget'] ?? null) === null) {
            return 'budget';
        }

        if ($this->stringOrNull($filledSlots['guest_count'] ?? null) === null) {
            return 'guest_count';
        }

        return null;
    }

    private function isRecommendationPrompt(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['cocok', 'rekomend', 'saran', 'yang pas', 'yang cocok'] as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSuppressPaymentFraming(string $currentIntent, ConversationStage $stage): bool
    {
        return $stage === ConversationStage::PaymentDiscussion
            && in_array($currentIntent, ['package_inquiry', 'price_inquiry'], true);
    }

    private function shouldSuppressDiscoveryProbe(string $currentIntent, ConversationStage $stage): bool
    {
        return in_array($stage, [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
            ConversationStage::Booked,
        ], true) && in_array($currentIntent, ['package_inquiry', 'price_inquiry'], true);
    }
}
