<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\FollowUpCheckResult;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use Carbon\CarbonImmutable;

class FollowUpPolicyService
{
    private const MAX_FOLLOW_UPS = 2;
    private const FU1_MIN_HOURS  = 18;
    private const FU2_MIN_HOURS  = 48;

    public function canSendFollowUp(Lead $lead): FollowUpCheckResult
    {
        if ($lead->automation_paused) {
            return FollowUpCheckResult::ineligible('automation_paused');
        }

        $state = $this->loadState($lead);
        $count = (int) ($state['follow_up_count'] ?? 0);

        if ($count >= self::MAX_FOLLOW_UPS) {
            return FollowUpCheckResult::ineligible('max_follow_up_reached');
        }

        $now = CarbonImmutable::now();

        if ($count === 0) {
            $lastMessageAt = $lead->last_message_at
                ? CarbonImmutable::instance($lead->last_message_at)
                : null;

            if (! $lastMessageAt) {
                return FollowUpCheckResult::ineligible('no_last_message_at');
            }

            if ($lastMessageAt->diffInHours($now) < self::FU1_MIN_HOURS) {
                return FollowUpCheckResult::ineligible('fu1_cooldown_not_elapsed');
            }

            return FollowUpCheckResult::eligible(1);
        }

        // count === 1 → check FU-2 eligibility
        $fu1SentAt = isset($state['follow_up_1_sent_at'])
            ? CarbonImmutable::parse($state['follow_up_1_sent_at'])
            : null;

        if (! $fu1SentAt) {
            return FollowUpCheckResult::ineligible('fu1_timestamp_missing');
        }

        if ($fu1SentAt->diffInHours($now) < self::FU2_MIN_HOURS) {
            return FollowUpCheckResult::ineligible('fu2_cooldown_not_elapsed');
        }

        return FollowUpCheckResult::eligible(2);
    }

    public function recordFollowUpSent(Lead $lead): void
    {
        $memory = $this->memoryForWrite($lead);
        $custom = $memory->custom_fields ?? [];

        $count = (int) ($custom['follow_up_count'] ?? 0);
        $next  = $count + 1;

        $custom['follow_up_count'] = $next;
        if ($next === 1) {
            $custom['follow_up_1_sent_at'] = CarbonImmutable::now()->toIso8601String();
        } elseif ($next === 2) {
            $custom['follow_up_2_sent_at'] = CarbonImmutable::now()->toIso8601String();
        }

        $memory->update(['custom_fields' => $custom]);
    }

    public function resetFollowUpState(Lead $lead): void
    {
        $memory = $lead->memory;
        if (! $memory) {
            return;
        }

        $custom = $memory->custom_fields ?? [];
        unset(
            $custom['follow_up_count'],
            $custom['follow_up_1_sent_at'],
            $custom['follow_up_2_sent_at'],
        );

        $memory->update(['custom_fields' => $custom]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(Lead $lead): array
    {
        return $lead->memory?->custom_fields ?? [];
    }

    private function memoryForWrite(Lead $lead): LeadMemory
    {
        return LeadMemory::firstOrCreate(
            ['lead_id' => $lead->id],
            ['tenant_id' => $lead->tenant_id],
        );
    }
}
