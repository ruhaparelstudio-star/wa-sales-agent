<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use Illuminate\Support\Str;

/**
 * Deterministic rule-based evaluator. Produces a per-turn scorecard stored on
 * conversation_turn_logs. Cheap; no LLM call. Replaceable behind the same
 * method signature if we later introduce an LLM-judge.
 *
 * @phpstan-type Score array{
 *   answered_latest_question: bool,
 *   repeated_question: bool,
 *   stage_aligned: bool,
 *   has_cta_when_due: bool,
 *   no_false_promises: bool
 * }
 */
class ResponseEvaluatorService
{
    private const FORBIDDEN_PROMISE_PATTERNS = [
        'pasti available',
        'pasti tersedia',
        'dijamin',
        'garansi harga',
        'paling murah',
        'harga termurah',
    ];

    private const CTA_MARKERS = [
        'booking',
        'jadwalkan',
        'pesan sekarang',
        'konfirmasi',
        'transfer',
        'dp',
        'book ya',
        'lanjut ke admin',
        'hubungi admin',
    ];

    private const CTA_REQUIRED_STAGES = [
        ConversationStage::Closing,
        ConversationStage::PaymentDiscussion,
    ];

    /**
     * @return Score
     */
    public function evaluate(
        string $reply,
        Conversation $conversation,
        ?ClassifierOutput $classifier,
        ?ConversationState $state,
        string $responseType,
    ): array {
        $replyNormalized = Str::lower($reply);

        return [
            'answered_latest_question' => $this->answeredLatestQuestion($replyNormalized, $classifier),
            'repeated_question' => $this->repeatedQuestion($reply, $state),
            'stage_aligned' => $this->stageAligned($conversation, $responseType),
            'has_cta_when_due' => $this->hasCtaWhenDue($replyNormalized, $conversation),
            'no_false_promises' => $this->noFalsePromises($replyNormalized),
        ];
    }

    private function answeredLatestQuestion(string $replyLower, ?ClassifierOutput $classifier): bool
    {
        if ($classifier === null || $classifier->intent === '') {
            return true;
        }

        if (in_array($classifier->intent, ['tanya_paket', 'bandingkan_paket'], true)) {
            return $this->isConcretePackageAnswer($replyLower);
        }

        $topics = match ($classifier->intent) {
            'tanya_harga' => ['harga', 'paket', 'idr', 'rp', 'juta', 'ribu'],
            'availability' => ['tanggal', 'available', 'tersedia', 'kosong', 'booking'],
            'payment_proof', 'payment_inquiry' => ['pembayaran', 'transfer', 'bank', 'rekening', 'bukti', 'dp', 'pelunasan'],
            'ready_to_book' => ['booking', 'konfirmasi', 'lanjut', 'form', 'data'],
            'complaint', 'objection' => ['maaf', 'paham', 'kami', 'solusi', 'bantu'],
            default => null,
        };

        if ($topics === null) {
            return true;
        }

        foreach ($topics as $t) {
            if (str_contains($replyLower, $t)) {
                return true;
            }
        }

        return false;
    }

    private function isConcretePackageAnswer(string $replyLower): bool
    {
        $weakMetaPatterns = [
            'paket yang kamu incar',
            'paket yang kamu cari',
            'sebutkan saja',
            'sebutin aja',
            'nanti aku bantu jelaskan',
            'mau lihat paket yang mana',
            'mau bahas paket yang mana',
            'kalau ada paket',
        ];

        $concreteMarkers = [
            'silver',
            'gold',
            'platinum',
            'premium',
            'basic',
            'termasuk',
            'include',
            'isi paket',
            'coverage',
            'dokumentasi',
            'foto',
            'video',
            'album',
            'jam',
            'crew',
            'rp',
            'idr',
            'juta',
            'ribu',
        ];

        $hasConcreteMarker = false;

        foreach ($concreteMarkers as $marker) {
            if (str_contains($replyLower, $marker)) {
                $hasConcreteMarker = true;
                break;
            }
        }

        if (! $hasConcreteMarker) {
            return false;
        }

        foreach ($weakMetaPatterns as $pattern) {
            if (str_contains($replyLower, $pattern) && ! $this->containsConcretePackageListShape($replyLower)) {
                return false;
            }
        }

        return true;
    }

    private function containsConcretePackageListShape(string $replyLower): bool
    {
        return str_contains($replyLower, 'paket silver')
            || str_contains($replyLower, 'paket gold')
            || str_contains($replyLower, 'paket platinum')
            || str_contains($replyLower, 'paket premium')
            || str_contains($replyLower, 'paket basic')
            || preg_match('/paket\s+[a-z0-9]+:\s+/u', $replyLower) === 1;
    }

    private function repeatedQuestion(string $reply, ?ConversationState $state): bool
    {
        if ($state === null || blank($state->last_agent_question)) {
            return false;
        }

        $currentQuestion = $this->extractQuestion($reply);
        if ($currentQuestion === null) {
            return false;
        }

        return $this->similar(
            Str::lower($currentQuestion),
            Str::lower((string) $state->last_agent_question),
        );
    }

    private function stageAligned(Conversation $conversation, string $responseType): bool
    {
        $stage = $conversation->stageEnum();

        return match ($responseType) {
            'pricelist' => in_array($stage, [
                ConversationStage::Qualification,
                ConversationStage::NeedsDiscovery,
                ConversationStage::PackageRecommendation,
                ConversationStage::PaymentDiscussion,
                ConversationStage::Closing,
            ], true),
            'handoff' => in_array($stage, [
                ConversationStage::PaymentDiscussion,
                ConversationStage::Closing,
                ConversationStage::HandoffToHuman,
            ], true),
            default => true,
        };
    }

    private function hasCtaWhenDue(string $replyLower, Conversation $conversation): bool
    {
        if (! in_array($conversation->stageEnum(), self::CTA_REQUIRED_STAGES, true)) {
            return true;
        }

        foreach (self::CTA_MARKERS as $marker) {
            if (str_contains($replyLower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function noFalsePromises(string $replyLower): bool
    {
        foreach (self::FORBIDDEN_PROMISE_PATTERNS as $pattern) {
            if (str_contains($replyLower, $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function extractQuestion(string $reply): ?string
    {
        $trimmed = trim($reply);
        if ($trimmed === '' || ! str_contains($trimmed, '?')) {
            return null;
        }

        $segments = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [$trimmed];
        $questions = array_values(array_filter($segments, static fn (string $s): bool => str_contains($s, '?')));

        return $questions === [] ? null : (string) end($questions);
    }

    private function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 80.0;
    }
}
