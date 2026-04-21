<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;

class QualityFilterService
{
    private const FAILURE_SEVERITY = [
        'false_promise' => 'hard',
        'stage_misaligned' => 'hard',
        'duplicate_reply' => 'hard',
        'unanswered_latest_question' => 'medium',
        'repeated_question' => 'medium',
        'missing_cta' => 'soft',
    ];

    public function __construct(
        private readonly ResponseEvaluatorService $responseEvaluatorService,
        private readonly ContextAwareFallbackBuilder $fallbackBuilder,
    ) {}

    /**
     * @return array{
     *   message: string,
     *   next_best_action: string,
     *   tool_result_summary: string,
     *   rewrite_reason: string,
     *   evaluator_score: array,
     *   handoff_reason_detail?: string,
     *   handoff_summary_for_admin?: string
     * }|null
     */
    public function filterGeneratedReply(
        string $reply,
        Lead $lead,
        Conversation $conversation,
        Message $message,
        ?ClassifierOutput $classifier = null,
        ?InterpretationResult $interpretation = null,
        string $responseType = 'text',
    ): ?array {
        $state = $conversation->state()->first();
        $userLatestTopic = $this->detectUserLatestTopic((string) $message->content, $classifier, $interpretation);
        $score = $this->responseEvaluatorService->evaluate(
            $reply,
            $conversation,
            $classifier,
            $state,
            $responseType,
        );

        $failures = $this->criticalFailures($score);
        $lastAgentMessage = is_string($state?->last_agent_message) ? $state->last_agent_message : null;

        if ($this->isNearDuplicateReply($reply, $lastAgentMessage)) {
            $failures[] = 'duplicate_reply';
        }

        if (
            in_array('missing_cta', $failures, true)
            && $conversation->stageEnum() === \App\Modules\Conversations\Enums\ConversationStage::PaymentDiscussion
            && $userLatestTopic === 'package'
            && $this->replyMatchesTopic($reply, $userLatestTopic)
        ) {
            $failures = array_values(array_filter(
                $failures,
                static fn (string $failure): bool => $failure !== 'missing_cta',
            ));
        }

        if ($failures === []) {
            return null;
        }

        $decision = $this->resolveDecision($failures);
        $nextBestAction = (string) ($state?->next_best_action ?? 'respond_to_user');

        if ($decision['mode'] === 'repair') {
            $repairedReply = $this->repairReply($reply, $conversation, $state, $nextBestAction, $failures);

            if ($repairedReply !== null) {
                if ($this->shouldKeepOriginalReply($reply, $repairedReply, $userLatestTopic)) {
                    return null;
                }

                return [
                    'message' => $repairedReply,
                    'next_best_action' => $nextBestAction,
                    'tool_result_summary' => sprintf(
                        'quality_filter_repaired:%s:tier_%s',
                        implode(',', $failures),
                        $decision['severity'],
                    ),
                    'rewrite_reason' => sprintf('repair:%s', implode(',', $failures)),
                    'evaluator_score' => $score,
                ];
            }
        }

        $fallback = $this->fallbackBuilder->build(
            $lead,
            $conversation,
            $message,
            $interpretation,
            $classifier,
            sprintf(
                'quality_filter_replaced:%s:tier_%s',
                implode(',', $failures),
                $decision['severity'],
            ),
        );

        if ($this->shouldKeepOriginalReply($reply, $fallback['message'], $userLatestTopic)) {
            return null;
        }

        if (in_array('duplicate_reply', $failures, true)
            && $this->isNearDuplicateReply($fallback['message'], $lastAgentMessage)) {
            return [
                'message' => $this->handoffEscalationMessage(),
                'next_best_action' => 'handoff_to_human',
                'tool_result_summary' => sprintf(
                    'quality_filter_escalated:%s:fallback_duplicate',
                    implode(',', $failures),
                ),
                'rewrite_reason' => sprintf('escalate:%s', implode(',', $failures)),
                'evaluator_score' => $score,
                'handoff_reason_detail' => 'quality_filter_duplicate_reply',
                'handoff_summary_for_admin' => 'Quality filter mendeteksi reply AI berulang/nyaris sama dengan pesan agent sebelumnya setelah normalisasi. Perlu follow-up manual agar percakapan tidak loop.',
            ];
        }

        return [
            'message' => $fallback['message'],
            'next_best_action' => $fallback['next_best_action'],
            'tool_result_summary' => $fallback['reason'],
            'rewrite_reason' => sprintf('replace:%s', implode(',', $failures)),
            'evaluator_score' => $score,
        ];
    }

    /**
     * @param  array{
     *   answered_latest_question: bool,
     *   repeated_question: bool,
     *   stage_aligned: bool,
     *   has_cta_when_due: bool,
     *   no_false_promises: bool
     * }  $score
     * @return list<string>
     */
    private function criticalFailures(array $score): array
    {
        $failures = [];

        if (($score['answered_latest_question'] ?? true) === false) {
            $failures[] = 'unanswered_latest_question';
        }

        if (($score['repeated_question'] ?? false) === true) {
            $failures[] = 'repeated_question';
        }

        if (($score['no_false_promises'] ?? true) === false) {
            $failures[] = 'false_promise';
        }

        if (($score['has_cta_when_due'] ?? true) === false) {
            $failures[] = 'missing_cta';
        }

        if (($score['stage_aligned'] ?? true) === false) {
            $failures[] = 'stage_misaligned';
        }

        return $failures;
    }

    /**
     * @param  list<string>  $failures
     * @return array{mode: 'repair'|'replace', severity: 'soft'|'medium'|'hard'}
     */
    private function resolveDecision(array $failures): array
    {
        $severity = 'soft';

        foreach ($failures as $failure) {
            $currentSeverity = self::FAILURE_SEVERITY[$failure] ?? 'medium';

            if ($currentSeverity === 'hard') {
                $severity = 'hard';
                break;
            }

            if ($currentSeverity === 'medium') {
                $severity = 'medium';
            }
        }

        return [
            'mode' => $this->canRepairFailures($failures) ? 'repair' : 'replace',
            'severity' => $severity,
        ];
    }

    /**
     * @param  list<string>  $failures
     */
    private function canRepairFailures(array $failures): bool
    {
        if ($failures === []) {
            return false;
        }

        foreach ($failures as $failure) {
            if (! in_array($failure, ['missing_cta', 'repeated_question'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isNearDuplicateReply(string $reply, ?string $lastAgentMessage): bool
    {
        $current = $this->normalizeReplyForDedup($reply);
        $previous = $this->normalizeReplyForDedup($lastAgentMessage ?? '');

        if ($current === '' || $previous === '') {
            return false;
        }

        if ($current === $previous) {
            return true;
        }

        similar_text($current, $previous, $similarityPercent);

        return $similarityPercent >= 92.0;
    }

    private function normalizeReplyForDedup(string $reply): string
    {
        $normalized = mb_strtolower(trim($reply));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function handoffEscalationMessage(): string
    {
        return 'Biar penjelasannya nggak muter, admin kami lanjut bantu dari chat terakhir ya.';
    }

    private function shouldKeepOriginalReply(string $originalReply, string $candidateReply, string $userLatestTopic): bool
    {
        if ($userLatestTopic === 'other') {
            return false;
        }

        return $this->replyMatchesTopic($originalReply, $userLatestTopic)
            && ! $this->replyMatchesTopic($candidateReply, $userLatestTopic);
    }

    /**
     * @param  list<string>  $failures
     */
    private function repairReply(
        string $reply,
        Conversation $conversation,
        ?ConversationState $state,
        string $nextBestAction,
        array $failures,
    ): ?string {
        $repairedReply = trim($reply);
        $changed = false;

        if (in_array('repeated_question', $failures, true)) {
            $withoutRepeatedQuestion = $this->removeRepeatedQuestion($repairedReply, $state);
            if ($withoutRepeatedQuestion === null) {
                return null;
            }

            if ($withoutRepeatedQuestion !== $repairedReply) {
                $repairedReply = $withoutRepeatedQuestion;
                $changed = true;
            }
        }

        if (in_array('missing_cta', $failures, true)) {
            $cta = $this->buildCtaRepair($conversation, $nextBestAction);
            if ($cta === null) {
                return $changed ? $repairedReply : null;
            }

            if ($repairedReply === '') {
                return $cta;
            }

            $separator = preg_match('/[.!?]$/u', $repairedReply) === 1 ? ' ' : '. ';
            $candidate = $repairedReply . $separator . $cta;

            if ($candidate !== $repairedReply) {
                $repairedReply = $candidate;
                $changed = true;
            }
        }

        return $changed ? trim($repairedReply) : null;
    }

    private function removeRepeatedQuestion(string $reply, ?ConversationState $state): ?string
    {
        if ($state === null || blank($state->last_agent_question)) {
            return null;
        }

        $segments = preg_split('/(?<=[.!?])\s+/u', trim($reply)) ?: [];
        if ($segments === []) {
            return null;
        }

        $filtered = [];
        foreach ($segments as $segment) {
            $trimmedSegment = trim($segment);
            if ($trimmedSegment === '') {
                continue;
            }

            if (
                str_contains($trimmedSegment, '?')
                && $this->questionsAreSimilar($trimmedSegment, (string) $state->last_agent_question)
            ) {
                continue;
            }

            $filtered[] = $trimmedSegment;
        }

        if ($filtered === [] || count($filtered) === count($segments)) {
            return null;
        }

        return implode(' ', $filtered);
    }

    private function questionsAreSimilar(string $currentQuestion, string $previousQuestion): bool
    {
        $current = mb_strtolower(trim($currentQuestion));
        $previous = mb_strtolower(trim($previousQuestion));

        if ($current === '' || $previous === '') {
            return false;
        }

        if ($current === $previous) {
            return true;
        }

        similar_text($current, $previous, $similarityPercent);

        return $similarityPercent >= 80.0;
    }

    private function buildCtaRepair(Conversation $conversation, string $nextBestAction): ?string
    {
        if (str_starts_with($nextBestAction, 'answer_payment_then_collect_')) {
            $field = substr($nextBestAction, strlen('answer_payment_then_collect_'));

            return sprintf(
                'Kalau sudah cocok, kita bisa lanjut booking. Boleh kirim %s dulu ya?',
                $this->labelForField($field),
            );
        }

        if (str_starts_with($nextBestAction, 'collect_')) {
            $field = substr($nextBestAction, strlen('collect_'));

            return sprintf(
                'Kalau mau kita lanjut, boleh kirim %s dulu ya?',
                $this->labelForField($field),
            );
        }

        if (str_starts_with($nextBestAction, 'ask_')) {
            $field = substr($nextBestAction, strlen('ask_'));

            return sprintf(
                'Kalau mau kita lanjut, boleh info %s dulu ya?',
                $this->labelForField($field),
            );
        }

        return match ($nextBestAction) {
            'guide_to_booking', 'confirm_booking_step', 'answer_payment_question' => 'Kalau sudah cocok, kita bisa lanjut ke langkah booking ya.',
            'respond_to_user', 'share_pricelist', 'continue_qualification' => match ($conversation->stageEnum()) {
                \App\Modules\Conversations\Enums\ConversationStage::Closing,
                \App\Modules\Conversations\Enums\ConversationStage::PaymentDiscussion => 'Kalau sudah cocok, kita bisa lanjut ke langkah booking ya.',
                default => null,
            },
            default => null,
        };
    }

    private function labelForField(string $field): string
    {
        return match ($field) {
            'service_type' => 'layanan yang dicari',
            'event_date' => 'tanggal acara',
            'location' => 'lokasi acara',
            'guest_count' => 'perkiraan jumlah tamu',
            'budget' => 'budget yang disiapkan',
            'name', 'nama_lengkap' => 'nama lengkap',
            default => str_replace('_', ' ', $field),
        };
    }

    private function detectUserLatestTopic(
        string $messageContent,
        ?ClassifierOutput $classifier,
        ?InterpretationResult $interpretation,
    ): string {
        $content = mb_strtolower(trim($messageContent));

        if ($this->hasExplicitPaymentLexicon($content)) {
            return 'payment';
        }

        if ($this->containsAny($content, ['harga', 'pricelist', 'price list', 'biaya', 'berapa'])) {
            return 'price';
        }

        if ($this->containsAny($content, ['paket', 'package', 'layanan', 'isi paket', 'apa aja', 'dapat apa', 'detail'])) {
            return 'package';
        }

        $intent = $classifier?->intent ?? $interpretation?->legacyIntent ?? '';

        return match ($intent) {
            'payment_inquiry', 'payment_proof' => 'payment',
            'ready_to_book' => 'booking',
            'tanya_harga' => 'price',
            'tanya_paket', 'bandingkan_paket' => 'package',
            default => 'other',
        };
    }

    private function replyMatchesTopic(string $reply, string $topic): bool
    {
        $content = mb_strtolower(trim($reply));

        if ($content === '') {
            return false;
        }

        return match ($topic) {
            'payment' => $this->hasExplicitPaymentLexicon($content) || $this->containsAny($content, ['pembayaran', 'rekening', 'bank']),
            'booking' => $this->containsAny($content, ['booking', 'konfirmasi', 'form', 'data acara', 'data booking']),
            'price', 'pricing' => $this->containsAny($content, ['harga', 'rp', 'idr', 'juta', 'ribu', 'pricelist', 'biaya']),
            'package' => $this->containsAny($content, ['paket', 'isi paket', 'termasuk', 'include', 'foto', 'video', 'album', 'dokumentasi', 'crew', 'jam']),
            default => true,
        };
    }

    private function hasExplicitPaymentLexicon(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        return preg_match('/\b(dp|bayar|payment|pelunasan|transfer|invoice)\b/u', $content) === 1
            || str_contains($content, 'booking lanjut')
            || str_contains($content, 'jadi booking')
            || str_contains($content, 'lanjut booking');
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
}
