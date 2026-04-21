<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\Conversations\Enums\ConversationStage;

class LeadReadinessScorer
{
    /**
     * @return array{score: int, label: string, signals: list<string>}
     */
    public function score(
        ConversationStage $stage,
        string $leadTemperature,
        bool $qualificationComplete,
        bool $discoveryComplete,
        bool $hasPaymentSignal,
        bool $hasBookingSignal,
        bool $hasPackageSignal,
        bool $bookingFieldsComplete,
        int $bookingFieldsMissingCount,
    ): array {
        $score = 0;
        $signals = [];

        $score += match ($leadTemperature) {
            'warm' => 10,
            'hot' => 20,
            default => 0,
        };

        $score += match ($stage) {
            ConversationStage::Qualification => 5,
            ConversationStage::NeedsDiscovery => 12,
            ConversationStage::PackageRecommendation => 25,
            ConversationStage::ObjectionHandling => 22,
            ConversationStage::PaymentDiscussion => 40,
            ConversationStage::Closing => 50,
            ConversationStage::FollowUp => 18,
            default => 0,
        };

        if ($qualificationComplete) {
            $score += 12;
            $signals[] = 'qualification_complete';
        }

        if ($discoveryComplete) {
            $score += 8;
            $signals[] = 'discovery_complete';
        }

        if ($hasPackageSignal) {
            $score += 14;
            $signals[] = 'package_interest';
        }

        if ($hasPaymentSignal) {
            $score += 24;
            $signals[] = 'payment_signal';
        }

        if ($hasBookingSignal) {
            $score += 35;
            $signals[] = 'booking_signal';
        }

        if ($bookingFieldsComplete && ($hasPaymentSignal || $hasBookingSignal || in_array($stage, [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
        ], true))) {
            $score += 10;
            $signals[] = 'booking_fields_complete';
        } elseif ($bookingFieldsMissingCount > 0 && ($hasPaymentSignal || $hasBookingSignal || in_array($stage, [
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
        ], true))) {
            $score += 6;
            $signals[] = 'booking_fields_pending';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'label' => match (true) {
                $score >= 75 => 'closing_ready',
                $score >= 55 => 'hot',
                $score >= 30 => 'warm',
                default => 'cold',
            },
            'signals' => array_values(array_unique($signals)),
        ];
    }
}
