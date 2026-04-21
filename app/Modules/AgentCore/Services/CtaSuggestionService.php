<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\Conversations\Enums\ConversationStage;

class CtaSuggestionService
{
    /**
     * @param  array{score: int, label: string, signals: list<string>}  $readiness
     * @return array{
     *   cta_level: string,
     *   answer_priority: string,
     *   next_best_action: string,
     *   booking_field_focus: string|null,
     *   suggested_cta_style: string
     * }
     */
    public function suggest(
        ConversationStage $stage,
        string $canonicalIntent,
        array $readiness,
        ?string $paymentTopic = null,
        ?string $bookingFieldFocus = null,
        bool $bookingFieldsComplete = false,
    ): array {
        if (in_array($stage, [
            ConversationStage::Booked,
            ConversationStage::HandoffToHuman,
            ConversationStage::Closed,
        ], true)) {
            return [
                'cta_level' => 'none',
                'answer_priority' => 'answer_latest_question_first',
                'next_best_action' => 'respond_to_user',
                'booking_field_focus' => null,
                'suggested_cta_style' => 'Jangan dorong closing baru di stage ini.',
            ];
        }

        if ($canonicalIntent === 'payment_inquiry') {
            return [
                'cta_level' => $readiness['score'] >= 80 ? 'hard' : 'medium',
                'answer_priority' => 'answer_payment_question_first',
                'next_best_action' => $bookingFieldFocus
                    ? 'answer_payment_then_collect_' . $bookingFieldFocus
                    : 'guide_to_booking',
                'booking_field_focus' => $bookingFieldFocus,
                'suggested_cta_style' => $bookingFieldFocus
                    ? sprintf(
                        'Jawab pertanyaan pembayaran dulu, lalu arahkan ke booking dengan meminta %s.',
                        $bookingFieldFocus,
                    )
                    : 'Jawab pertanyaan pembayaran dulu, lalu beri next step booking yang konkret.',
            ];
        }

        if ($canonicalIntent === 'booking_intent' || $stage === ConversationStage::Closing) {
            return [
                'cta_level' => 'hard',
                'answer_priority' => 'answer_latest_question_first',
                'next_best_action' => $bookingFieldFocus
                    ? 'collect_' . $bookingFieldFocus
                    : ($bookingFieldsComplete ? 'confirm_booking_step' : 'guide_to_booking'),
                'booking_field_focus' => $bookingFieldFocus,
                'suggested_cta_style' => $bookingFieldFocus
                    ? sprintf('Tutup dengan ajakan lanjut booking dan minta %s.', $bookingFieldFocus)
                    : 'Tutup dengan langkah booking paling konkret tanpa kembali ke discovery umum.',
            ];
        }

        if (in_array($stage, [
            ConversationStage::PackageRecommendation,
            ConversationStage::ObjectionHandling,
            ConversationStage::FollowUp,
        ], true) && $readiness['score'] >= 55) {
            return [
                'cta_level' => $readiness['score'] >= 70 ? 'medium' : 'soft',
                'answer_priority' => 'answer_latest_question_first',
                'next_best_action' => 'guide_to_booking',
                'booking_field_focus' => null,
                'suggested_cta_style' => $readiness['score'] >= 70
                    ? 'Tutup dengan next step yang jelas untuk lanjut booking bila user sudah cocok.'
                    : 'Tutup dengan CTA ringan untuk lanjut cek langkah booking jika user siap.',
            ];
        }

        if ($readiness['score'] >= 45 && in_array($stage, [
            ConversationStage::Qualification,
            ConversationStage::NeedsDiscovery,
        ], true)) {
            return [
                'cta_level' => 'soft',
                'answer_priority' => 'answer_latest_question_first',
                'next_best_action' => 'respond_to_user',
                'booking_field_focus' => null,
                'suggested_cta_style' => 'Tetap jawab dulu, lalu boleh tutup dengan CTA ringan tanpa memaksa closing.',
            ];
        }

        return [
            'cta_level' => 'none',
            'answer_priority' => 'answer_latest_question_first',
            'next_best_action' => 'respond_to_user',
            'booking_field_focus' => null,
            'suggested_cta_style' => 'Belum perlu CTA penutupan yang eksplisit. Fokus bantu jawaban inti user.',
        ];
    }
}
