<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\DTOs\ToneProfileDto;
use App\Modules\Tenancy\Enums\ToneFormality;

class HumanizerService
{
    /**
     * @return array{message: string, reasons: list<string>}
     */
    public function humanizeWithMetadata(
        string $reply,
        Lead $lead,
        Conversation $conversation,
        ?Message $message = null,
    ): array {
        $reasons = [];

        $normalized = $this->normalizeWhitespace($reply);
        if ($normalized !== $reply) {
            $reasons[] = 'normalize_whitespace';
        }

        $punctuationNormalized = $this->normalizePunctuation($normalized);
        if ($punctuationNormalized !== $normalized) {
            $reasons[] = 'normalize_punctuation';
        }

        $normalized = $punctuationNormalized;

        if ($normalized === '') {
            return [
                'message' => '',
                'reasons' => $reasons,
            ];
        }

        if (! str_contains($normalized, "\n")) {
            $deRepeated = $this->deRepeatOpening($normalized, $lead, $conversation);
            if ($deRepeated !== $normalized) {
                $reasons[] = 'rotate_opening';
            }

            $normalized = $deRepeated;
        }

        $deduped = $this->stripDuplicateAcknowledgment($normalized, $message);
        if ($deduped !== $normalized) {
            $reasons[] = 'dedupe_acknowledgment';
        }

        return [
            'message' => trim($deduped),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    public function humanize(
        string $reply,
        Lead $lead,
        Conversation $conversation,
        ?Message $message = null,
    ): string {
        return $this->humanizeWithMetadata($reply, $lead, $conversation, $message)['message'];
    }

    private function normalizeWhitespace(string $reply): string
    {
        $reply = str_replace(["\r\n", "\r"], "\n", trim($reply));
        $reply = preg_replace('/[ \t]+/', ' ', $reply) ?? $reply;
        $reply = preg_replace("/\n{3,}/", "\n\n", $reply) ?? $reply;

        return preg_replace('/ ?([,.!?])/', '$1', $reply) ?? $reply;
    }

    private function normalizePunctuation(string $reply): string
    {
        $reply = preg_replace('/([!?.,])\1{1,}/u', '$1', $reply) ?? $reply;
        $reply = preg_replace('/\s+([!?.,])/u', '$1', $reply) ?? $reply;

        return $reply;
    }

    private function deRepeatOpening(string $reply, Lead $lead, Conversation $conversation): string
    {
        $state = $conversation->state()->first();
        $lastAgentMessage = is_string($state?->last_agent_message) ? trim($state->last_agent_message) : '';
        if ($lastAgentMessage === '') {
            return $reply;
        }

        $currentOpening = $this->extractOpeningKey($reply);
        $lastOpening = $this->extractOpeningKey($lastAgentMessage);

        if ($currentOpening === null || $currentOpening !== $lastOpening) {
            return $reply;
        }

        $tone = ToneProfileDto::fromTenant($lead->tenant);
        $replacement = $this->alternateOpening($currentOpening, $tone->formality);

        if ($replacement === null) {
            return $reply;
        }

        return preg_replace('/^' . preg_quote($this->openingPrefix($currentOpening), '/') . '/iu', $replacement, $reply, 1) ?? $reply;
    }

    private function stripDuplicateAcknowledgment(string $reply, ?Message $message): string
    {
        if ($message === null) {
            return $reply;
        }

        $userMessage = mb_strtolower(trim((string) $message->content));
        if ($userMessage === '') {
            return $reply;
        }

        $patterns = [
            'siap, siap' => 'siap',
            'oke, oke' => 'oke',
            'baik, baik' => 'baik',
        ];

        $lower = mb_strtolower($reply);
        foreach ($patterns as $pattern => $replacement) {
            if (str_starts_with($lower, $pattern)) {
                return preg_replace('/^' . preg_quote($pattern, '/') . '/iu', $replacement, $reply, 1) ?? $reply;
            }
        }

        return $reply;
    }

    private function extractOpeningKey(string $reply): ?string
    {
        $normalized = mb_strtolower(trim($reply));

        return match (true) {
            str_starts_with($normalized, 'siap,') => 'siap',
            str_starts_with($normalized, 'oke,') => 'oke',
            str_starts_with($normalized, 'baik,') => 'baik',
            str_starts_with($normalized, 'halo kak,') => 'halo_kak',
            str_starts_with($normalized, 'paham,') => 'paham',
            default => null,
        };
    }

    private function openingPrefix(string $openingKey): string
    {
        return match ($openingKey) {
            'siap' => 'Siap,',
            'oke' => 'Oke,',
            'baik' => 'Baik,',
            'halo_kak' => 'Halo Kak,',
            'paham' => 'Paham,',
            default => '',
        };
    }

    private function alternateOpening(string $openingKey, ToneFormality $formality): ?string
    {
        return match ($formality) {
            ToneFormality::Formal => match ($openingKey) {
                'siap', 'oke' => 'Baik,',
                'halo_kak' => 'Halo,',
                default => null,
            },
            ToneFormality::SemiCasual => match ($openingKey) {
                'siap' => 'Oke,',
                'oke' => 'Baik,',
                'baik' => 'Oke,',
                'halo_kak' => 'Halo,',
                default => null,
            },
            ToneFormality::Casual => match ($openingKey) {
                'siap', 'baik' => 'Oke,',
                'oke' => 'Sip,',
                'halo_kak' => 'Halo,',
                default => null,
            },
        };
    }
}
