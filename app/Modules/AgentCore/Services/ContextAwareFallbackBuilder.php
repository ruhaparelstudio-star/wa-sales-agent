<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;

class ContextAwareFallbackBuilder
{
    public function __construct(
        private readonly LeadMemoryService $leadMemoryService,
        private readonly ConversationStageService $conversationStageService,
        private readonly ClosingPolicyService $closingPolicyService,
    ) {}

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    public function build(
        Lead $lead,
        Conversation $conversation,
        Message $message,
        ?InterpretationResult $interpretation = null,
        ?ClassifierOutput $classifier = null,
        string $reason = 'context_aware_fallback',
    ): array {
        $content = strtolower(trim((string) $message->content));
        $stage = $conversation->stageEnum();
        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        $nextExpectedField = $this->conversationStageService->nextExpectedField($conversation, $snapshot);
        $closingPolicy = $this->closingPolicyService->resolve($conversation, $lead, $classifier, $interpretation);
        $bookingFieldFocus = $closingPolicy['booking_field_focus'] ?? null;
        $canonicalIntent = $interpretation?->canonicalIntent ?? $closingPolicy['canonical_intent'] ?? 'unclear';
        $paymentTopic = $closingPolicy['payment_topic'] ?? null;
        $lastAgentQuestion = mb_strtolower(trim((string) ($conversation->state?->last_agent_question
            ?? $conversation->state()->value('last_agent_question')
            ?? '')));

        if ($this->isGreetingMessage($content)) {
            return [
                'message' => 'Halo Kak, aku siap bantu ya. Boleh share tanggal atau rencana acaranya dulu?',
                'next_best_action' => 'ask_event_date',
                'reason' => $reason . ':greeting',
            ];
        }

        if ($this->isDiscountInquiry($content)) {
            return [
                'message' => 'Untuk promo yang sedang aktif, detailnya belum ada di data yang aku pegang. Kalau mau, aku bantu arahin ke paket yang paling relevan dulu ya.',
                'next_best_action' => $nextExpectedField !== null ? 'ask_' . $nextExpectedField : 'respond_to_user',
                'reason' => $reason . ':discount',
            ];
        }

        if ($this->isTestMessage($content)) {
            return [
                'message' => 'Halo, pesannya sudah masuk ya. Kalau mau lanjut, aku bisa bantu bahas paket, harga, atau langkah booking.',
                'next_best_action' => 'respond_to_user',
                'reason' => $reason . ':test',
            ];
        }

        return match ($canonicalIntent) {
            'availability_inquiry' => $this->buildAvailabilityFallback($nextExpectedField, $reason),
            'payment_inquiry' => $this->buildPaymentFallback($paymentTopic, $bookingFieldFocus, $reason),
            'booking_intent' => $this->buildBookingFallback($bookingFieldFocus, $reason),
            'price_inquiry', 'package_inquiry' => $this->buildPricingFallback($stage, $nextExpectedField, $closingPolicy, $content, $lastAgentQuestion, $reason),
            'objection' => $this->buildObjectionFallback($stage, $reason),
            'follow_up' => $this->buildFollowUpFallback($stage, $closingPolicy, $reason),
            default => $this->buildStageAwareClarification($stage, $nextExpectedField, $closingPolicy, $reason),
        };
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildAvailabilityFallback(?string $nextExpectedField, string $reason): array
    {
        $message = $nextExpectedField === 'event_date'
            ? 'Siap, untuk cek ketersediaan aku bantu arahkan ya. Boleh info tanggal acaranya dulu?'
            : 'Siap, untuk cek ketersediaan aku bantu arahkan ya. Kalau tanggalnya sudah pasti, kirim saja biar konteksnya tidak salah.';

        return [
            'message' => $message,
            'next_best_action' => $nextExpectedField === 'event_date' ? 'ask_event_date' : 'handoff_to_human',
            'reason' => $reason . ':availability',
        ];
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildPaymentFallback(?string $paymentTopic, ?string $bookingFieldFocus, string $reason): array
    {
        $topic = match ($paymentTopic) {
            'down_payment' => 'soal DP',
            'settlement' => 'soal pelunasan',
            'payment_method' => 'soal metode pembayaran',
            'payment_proof' => 'soal konfirmasi pembayaran',
            default => 'soal pembayaran',
        };

        return [
            'message' => $bookingFieldFocus
                ? sprintf('Siap, aku bantu %s ya. Kalau kamu sudah cocok, kita bisa lanjut booking dan mulai dari %s dulu.', $topic, $this->labelForField($bookingFieldFocus))
                : sprintf('Siap, aku bantu %s ya. Kalau kamu sudah siap lanjut, aku bisa arahkan ke langkah booking yang paling pas.', $topic),
            'next_best_action' => $bookingFieldFocus
                ? 'answer_payment_then_collect_' . $bookingFieldFocus
                : 'answer_payment_question',
            'reason' => $reason . ':payment',
        ];
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildBookingFallback(?string $bookingFieldFocus, string $reason): array
    {
        return [
            'message' => $bookingFieldFocus
                ? sprintf('Siap, kita bisa lanjut booking ya. Boleh kirim %s dulu biar aku rapikan langkah berikutnya?', $this->labelForField($bookingFieldFocus))
                : 'Siap, kalau mau lanjut booking kita bisa teruskan langkahnya sekarang ya.',
            'next_best_action' => $bookingFieldFocus ? 'collect_' . $bookingFieldFocus : 'guide_to_booking',
            'reason' => $reason . ':booking',
        ];
    }

    /**
     * @param  array<string, mixed>  $closingPolicy
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildPricingFallback(
        ConversationStage $stage,
        ?string $nextExpectedField,
        array $closingPolicy,
        string $content,
        string $lastAgentQuestion,
        string $reason,
    ): array {
        $pricingFocus = $this->detectPricingFocus($content);
        if ($pricingFocus !== null) {
            if (in_array($stage, [ConversationStage::Qualification, ConversationStage::NeedsDiscovery], true) && $nextExpectedField !== null) {
                return $this->buildFocusedPricingCollectionFallback(
                    $pricingFocus,
                    $nextExpectedField,
                    $this->isPricingChoiceQuestion($lastAgentQuestion),
                    $reason,
                );
            }

            return $this->buildFocusedPricingFallback(
                $pricingFocus,
                $closingPolicy,
                $this->isPricingChoiceQuestion($lastAgentQuestion),
                $reason,
            );
        }

        if (in_array($stage, [ConversationStage::Qualification, ConversationStage::NeedsDiscovery], true) && $nextExpectedField !== null) {
            return [
                'message' => sprintf(
                    'Siap, biar aku arahin paket yang paling pas, boleh info %s dulu?',
                    $this->labelForField($nextExpectedField),
                ),
                'next_best_action' => 'ask_' . $nextExpectedField,
                'reason' => $reason . ':pricing_missing_slot',
            ];
        }

        if (($closingPolicy['cta_level'] ?? 'none') !== 'none') {
            return [
                'message' => 'Siap, aku bantu jelaskan ya. Kalau sudah cocok, kita bisa lanjut ke langkah booking yang paling pas.',
                'next_best_action' => $closingPolicy['next_best_action'],
                'reason' => $reason . ':pricing_closing_ready',
            ];
        }

        return $this->buildNonGenericPricingFallback($content, $reason);
    }

    /**
     * @param  array<string, mixed>  $closingPolicy
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildFocusedPricingFallback(
        string $pricingFocus,
        array $closingPolicy,
        bool $hasRepeatedChoiceQuestion,
        string $reason,
    ): array {
        $nextBestAction = ($closingPolicy['cta_level'] ?? 'none') !== 'none'
            ? $closingPolicy['next_best_action']
            : 'respond_to_user';

        $followUpClause = $hasRepeatedChoiceQuestion
            ? ' Biar nggak muter, aku lanjut dari bagian itu ya.'
            : '';

        $message = match ($pricingFocus) {
            'price_only' => 'Siap, berarti kita fokus ke harga dulu ya.' . $followUpClause,
            'package_only' => 'Siap, berarti kita fokus ke isi paket dulu ya.' . $followUpClause,
            default => 'Siap, berarti kita fokus ke harga dan isi paket dulu ya.' . $followUpClause,
        };

        if (($closingPolicy['cta_level'] ?? 'none') !== 'none') {
            $message .= ' Kalau sudah cocok, kita bisa lanjut ke langkah booking yang paling pas.';
        } elseif ($pricingFocus === 'package_only') {
            $message .= ' Kalau mau lihat bagian tertentu, misalnya foto, video, atau album, sebutkan aja, nanti aku lanjut dari situ.';
        } elseif ($pricingFocus === 'price_only') {
            $message .= ' Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu fokus ke bagian harganya.';
        } else {
            $message .= ' Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu jelaskan yang paling relevan.';
        }

        return [
            'message' => $message,
            'next_best_action' => $nextBestAction,
            'reason' => $reason . ':pricing_focus_' . $pricingFocus,
        ];
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildFocusedPricingCollectionFallback(
        string $pricingFocus,
        string $nextExpectedField,
        bool $hasRepeatedChoiceQuestion,
        string $reason,
    ): array {
        $focusLead = match ($pricingFocus) {
            'price_only' => 'Siap, aku bantu fokus ke harga dulu ya.',
            'package_only' => 'Siap, aku bantu fokus ke isi paket dulu ya.',
            default => 'Siap, aku bantu fokus ke harga dan isi paket dulu ya.',
        };

        if ($hasRepeatedChoiceQuestion) {
            $focusLead .= ' Biar nggak muter, aku lanjut dari situ ya.';
        }

        return [
            'message' => sprintf(
                '%s Biar rekomendasinya pas, boleh info %s dulu?',
                $focusLead,
                $this->labelForField($nextExpectedField),
            ),
            'next_best_action' => 'ask_' . $nextExpectedField,
            'reason' => $reason . ':pricing_focus_collect_' . $pricingFocus,
        ];
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildNonGenericPricingFallback(string $content, string $reason): array
    {
        $pricingFocus = $this->detectPricingFocus($content);

        $message = match ($pricingFocus) {
            'price_only' => 'Siap, aku bantu fokus ke harga dulu ya. Kalau ada paket yang kamu incar, sebutkan aja, nanti aku bantu arahkan ke kisaran harganya.',
            'package_only' => 'Siap, aku bantu jelaskan isi paketnya dulu ya. Kalau mau lihat bagian tertentu, misalnya foto, video, atau album, sebutkan aja, nanti aku lanjut dari situ.',
            'price_and_package' => 'Siap, aku bantu jelaskan harga dan isi paketnya dulu ya. Kalau ada paket yang kamu incar, sebutkan aja, nanti aku lanjut dari situ.',
            default => 'Siap, aku bantu jelaskan paket atau harganya ya. Kalau ada bagian yang mau kamu lihat dulu, sebutkan aja, nanti aku lanjut dari situ.',
        };

        return [
            'message' => $message,
            'next_best_action' => 'respond_to_user',
            'reason' => $reason . ':pricing',
        ];
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildObjectionFallback(ConversationStage $stage, string $reason): array
    {
        $message = in_array($stage, [ConversationStage::PaymentDiscussion, ConversationStage::Closing], true)
            ? 'Paham kok, concern-nya penting. Yang masih bikin ragu di bagian pembayaran, harga, atau langkah bookingnya?'
            : 'Paham kok, concern-nya penting. Yang paling bikin kamu ragu di harga, isi paket, atau hal lain?';

        return [
            'message' => $message,
            'next_best_action' => 'respond_to_user',
            'reason' => $reason . ':objection',
        ];
    }

    /**
     * @param  array<string, mixed>  $closingPolicy
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildFollowUpFallback(
        ConversationStage $stage,
        array $closingPolicy,
        string $reason,
    ): array {
        if (in_array($stage, [ConversationStage::PaymentDiscussion, ConversationStage::Closing], true)) {
            return [
                'message' => 'Siap, kita lanjut dari yang tadi ya. Kalau kamu sudah siap, aku bisa bantu masuk ke langkah booking berikutnya.',
                'next_best_action' => $closingPolicy['next_best_action'] ?? 'guide_to_booking',
                'reason' => $reason . ':follow_up_closing',
            ];
        }

        return [
            'message' => 'Siap, kita lanjut dari yang tadi ya. Kamu mau aku bantu bahas harga, paket, atau next step yang paling relevan?',
            'next_best_action' => 'respond_to_user',
            'reason' => $reason . ':follow_up',
        ];
    }

    /**
     * @param  array<string, mixed>  $closingPolicy
     * @return array{message: string, next_best_action: string, reason: string}
     */
    private function buildStageAwareClarification(
        ConversationStage $stage,
        ?string $nextExpectedField,
        array $closingPolicy,
        string $reason,
    ): array {
        if (in_array($stage, [
            ConversationStage::NewLead,
            ConversationStage::Qualification,
            ConversationStage::NeedsDiscovery,
        ], true) && $nextExpectedField !== null) {
            return [
                'message' => sprintf(
                    'Siap, biar aku sambungkan dengan kebutuhanmu, boleh info %s dulu?',
                    $this->labelForField($nextExpectedField),
                ),
                'next_best_action' => 'ask_' . $nextExpectedField,
                'reason' => $reason . ':missing_field',
            ];
        }

        if ($stage === ConversationStage::PaymentDiscussion) {
            return [
                'message' => 'Siap, aku bantu lanjut soal pembayaran ya. Kamu mau bahas DP, pelunasan, atau metode bayarnya dulu?',
                'next_best_action' => 'answer_payment_question',
                'reason' => $reason . ':payment_stage',
            ];
        }

        if ($stage === ConversationStage::Closing) {
            $bookingFieldFocus = $closingPolicy['booking_field_focus'] ?? null;

            return [
                'message' => $bookingFieldFocus
                    ? sprintf('Siap, kita lanjut booking ya. Boleh kirim %s dulu?', $this->labelForField($bookingFieldFocus))
                    : 'Siap, kalau sudah cocok kita bisa lanjut ke langkah booking sekarang ya.',
                'next_best_action' => $bookingFieldFocus ? 'collect_' . $bookingFieldFocus : 'guide_to_booking',
                'reason' => $reason . ':closing_stage',
            ];
        }

        if (($closingPolicy['cta_level'] ?? 'none') !== 'none') {
            return [
                'message' => 'Siap, aku bantu lanjut ya. Kalau sudah pas, kita bisa masuk ke next step yang paling relevan sekarang.',
                'next_best_action' => $closingPolicy['next_best_action'],
                'reason' => $reason . ':closing_policy',
            ];
        }

        return [
            'message' => 'Siap, aku bantu lanjut ya. Mau kita fokus ke paket yang paling cocok atau langkah berikutnya dulu?',
            'next_best_action' => 'respond_to_user',
            'reason' => $reason . ':general',
        ];
    }

    private function labelForField(string $field): string
    {
        return match ($field) {
            'service_type' => 'layanan yang dicari',
            'event_date' => 'tanggal acara',
            'location' => 'lokasi acara',
            'guest_count' => 'perkiraan jumlah tamu',
            'budget' => 'budget yang disiapkan',
            'name' => 'nama lengkap',
            default => str_replace('_', ' ', $field),
        };
    }

    private function isGreetingMessage(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        return str_contains($content, 'halo')
            || str_contains($content, 'hai')
            || str_contains($content, 'hi')
            || str_contains($content, 'pagi')
            || str_contains($content, 'siang')
            || str_contains($content, 'sore')
            || str_contains($content, 'malam');
    }

    private function isDiscountInquiry(string $content): bool
    {
        return str_contains($content, 'diskon')
            || str_contains($content, 'promo')
            || str_contains($content, 'potongan harga');
    }

    private function isTestMessage(string $content): bool
    {
        return $content === 'tes'
            || $content === 'test'
            || $content === 'testing'
            || $content === 'cek'
            || $content === 'check';
    }

    private function detectPricingFocus(string $content): ?string
    {
        $asksPrice = str_contains($content, 'harga')
            || str_contains($content, 'pricelist')
            || str_contains($content, 'price list')
            || str_contains($content, 'daftar harga');
        $asksPackage = str_contains($content, 'isi paket')
            || str_contains($content, 'detail paket')
            || str_contains($content, 'paket ')
            || str_contains($content, ' package')
            || str_contains($content, 'package ')
            || str_contains($content, 'paketnya')
            || str_contains($content, 'paket nya')
            || str_contains($content, 'coverage');

        return match (true) {
            $asksPrice && $asksPackage => 'price_and_package',
            $asksPackage => 'package_only',
            $asksPrice => 'price_only',
            default => null,
        };
    }

    private function isPricingChoiceQuestion(string $question): bool
    {
        if ($question === '') {
            return false;
        }

        $asksPrice = str_contains($question, 'harga');
        $asksPackage = str_contains($question, 'isi paket') || str_contains($question, 'paket');
        $asksChoice = str_contains($question, 'mana yang paling cocok') || str_contains($question, 'lebih perlu lihat');

        return $asksPrice && $asksPackage && $asksChoice;
    }
}
