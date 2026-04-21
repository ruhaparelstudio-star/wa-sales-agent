<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Str;

class FallbackGuardService
{
    public function __construct(
        private readonly ContextAwareFallbackBuilder $fallbackBuilder,
    ) {}

    /**
     * @return array{message: string, next_best_action: string, tool_result_summary: string, rewrite_reason: string}|null
     */
    public function guardGeneratedReply(
        string $reply,
        Lead $lead,
        Conversation $conversation,
        Message $message,
        ?ClassifierOutput $classifier = null,
        ?InterpretationResult $interpretation = null,
    ): ?array {
        if (! $this->containsGenericResetLanguage($reply)) {
            return null;
        }

        if ($this->hasConcreteAnswerSignals($reply, $message, $classifier, $interpretation)) {
            return null;
        }

        $fallback = $this->fallbackBuilder->build(
            $lead,
            $conversation,
            $message,
            $interpretation,
            $classifier,
            'generic_reset_reply_replaced',
        );

        return [
            'message' => $fallback['message'],
            'next_best_action' => $fallback['next_best_action'],
            'tool_result_summary' => $fallback['reason'],
            'rewrite_reason' => 'generic_reset_without_concrete_answer',
        ];
    }

    public function containsGenericResetLanguage(string $reply): bool
    {
        $normalized = mb_strtolower(trim($reply));
        if ($normalized === '') {
            return false;
        }

        $patterns = [
            'ada yang bisa saya bantu',
            'ada yang bisa aku bantu',
            'apa yang bisa saya bantu',
            'apa yang bisa aku bantu',
            'mau tanya apa',
            'ingin tanya apa',
            'yang paling ingin kamu cari tahu apa',
            'yang ingin kamu tanyakan apa',
            'silakan tanya',
            'bisa saya bantu apa',
            'bisa aku bantu apa',
            'lebih perlu lihat harga, isi paket',
            'mana yang paling cocok buat kebutuhanmu',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasConcreteAnswerSignals(
        string $reply,
        Message $message,
        ?ClassifierOutput $classifier = null,
        ?InterpretationResult $interpretation = null,
    ): bool {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($reply)) ?: [];
        $nonGenericSentences = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '' || $this->containsGenericResetLanguage($sentence)) {
                continue;
            }

            $nonGenericSentences[] = $sentence;
        }

        $normalized = Str::lower(trim(implode(' ', $nonGenericSentences)));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(rp|idr|\d+%|\d{1,2}[:.]\d{2}|\d{1,2}\s?(juta|ribu))\b/u', $normalized) === 1) {
            return true;
        }

        $keywords = [
            'paket',
            'gold',
            'silver',
            'platinum',
            'harga',
            'pricelist',
            'daftar harga',
            'dp',
            'transfer',
            'rekening',
            'pelunasan',
            'bukti bayar',
            'booking',
            'form',
            'tanggal',
            'tersedia',
            'available',
            'lokasi',
            'bandung',
        ];

        $intent = $classifier?->intent ?? $interpretation?->legacyIntent ?? '';
        $keywords = array_merge($keywords, match ($intent) {
            'payment_inquiry', 'payment_proof' => ['pembayaran', 'dp', 'transfer', 'rekening', 'bank'],
            'tanya_harga', 'bandingkan_paket' => ['harga', 'paket', 'pricelist', 'isi paket'],
            'tanya_paket' => ['paket', 'isi paket', 'coverage'],
            'ready_to_book' => ['booking', 'jadwal', 'konfirmasi', 'data booking'],
            'availability' => ['tanggal', 'tersedia', 'available', 'kosong'],
            default => [],
        });

        $messageWords = preg_split('/\s+/u', Str::lower((string) $message->content)) ?: [];
        foreach ($messageWords as $word) {
            $word = trim($word, " \t\n\r\0\x0B.,!?");
            if (mb_strlen($word) >= 5) {
                $keywords[] = $word;
            }
        }

        foreach (array_unique($keywords) as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return true;
            }
        }

        foreach ($nonGenericSentences as $sentence) {
            if (mb_strlen($sentence) >= 40) {
                return true;
            }
        }

        return false;
    }
}
