<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\GuardrailResult;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;
use App\Modules\Subscription\Exceptions\SubscriptionException;
use App\Modules\Subscription\Services\SubscriptionEnforcementService;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Carbon\CarbonImmutable;

class GuardrailService
{
    public function __construct(
        private readonly SubscriptionEnforcementService $subscriptionEnforcement,
        private readonly RiskPolicyService $riskPolicy,
    ) {}

    public function check(Lead $lead, Conversation $conv): GuardrailResult
    {
        if ($conv->is_human_takeover) {
            return GuardrailResult::block('conversation_human_takeover');
        }

        if ($lead->automation_paused) {
            return GuardrailResult::block('lead_automation_paused');
        }

        try {
            $this->subscriptionEnforcement->assertCanSendOutbound($lead->tenant);
        } catch (SubscriptionException $e) {
            return GuardrailResult::block('subscription_blocked:' . $e->getMessage());
        }

        $agent = $conv->whatsapp_agent_id
            ? WhatsAppAgent::find($conv->whatsapp_agent_id)
            : null;

        if (! $agent || $agent->status !== AgentStatus::Connected) {
            return GuardrailResult::block('agent_not_connected');
        }

        if ($this->isQuietHours($lead)) {
            return GuardrailResult::block('quiet_hours');
        }

        if ($this->riskPolicy->isHighRisk($lead)) {
            return GuardrailResult::block('high_risk_score');
        }

        return GuardrailResult::allow();
    }

    private function isQuietHours(Lead $lead): bool
    {
        $settings = $lead->tenant?->settings ?? [];
        $start = $settings['quiet_hours_start'] ?? null;
        $end   = $settings['quiet_hours_end']   ?? null;

        if (! $start || ! $end) {
            return false;
        }

        $now = CarbonImmutable::now();
        $currentMinutes = $now->hour * 60 + $now->minute;
        $startMinutes   = $this->toMinutes($start);
        $endMinutes     = $this->toMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        // Handle range that does not cross midnight (e.g. 01:00 → 06:00)
        if ($startMinutes < $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
        }

        // Range that crosses midnight (e.g. 22:00 → 06:00)
        return $currentMinutes >= $startMinutes || $currentMinutes < $endMinutes;
    }

    private function toMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mn = (int) $m[2];
        if ($h < 0 || $h > 23 || $mn < 0 || $mn > 59) {
            return null;
        }
        return $h * 60 + $mn;
    }
}
