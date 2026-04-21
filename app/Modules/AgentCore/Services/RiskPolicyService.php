<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Leads\Models\Lead;

class RiskPolicyService
{
    private const HIGH_RISK_THRESHOLD = 70;
    private const MAX_RISK_SCORE = 100;

    private const OPT_OUT_KEYWORDS = [
        'stop', 'jangan hubungi', 'hapus', 'unsubscribe', 'tidak mau', 'berhenti',
    ];

    public function calculateRisk(Lead $lead, ClassifierOutput $classifier, ?string $lastInboundText = null): int
    {
        $score = 0;

        if ($classifier->sentiment === 'negative') {
            $score += 30;
        }

        if ($classifier->intent === 'complaint') {
            $score += 25;
        }

        if ($classifier->intent === 'opt_out') {
            $score += 50;
        }

        if ($this->containsOptOutKeyword($lastInboundText)) {
            $score += 50;
        }

        $followUpCount = $this->resolveFollowUpCount($lead);
        if ($followUpCount >= 2) {
            $score += 20;
        }

        $score = min($score, self::MAX_RISK_SCORE);

        $lead->update(['risk_score' => $score]);

        return $score;
    }

    public function isHighRisk(Lead $lead): bool
    {
        return $lead->risk_score > self::HIGH_RISK_THRESHOLD;
    }

    public function highRiskThreshold(): int
    {
        return self::HIGH_RISK_THRESHOLD;
    }

    private function containsOptOutKeyword(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $haystack = mb_strtolower($text);
        foreach (self::OPT_OUT_KEYWORDS as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveFollowUpCount(Lead $lead): int
    {
        $memory = $lead->memory;
        $custom = $memory?->custom_fields ?? [];

        return (int) ($custom['follow_up_count'] ?? 0);
    }
}
