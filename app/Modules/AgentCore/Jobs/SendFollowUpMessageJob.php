<?php

namespace App\Modules\AgentCore\Jobs;

use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\AgentCore\Services\DelayPolicyService;
use App\Modules\AgentCore\Services\FollowUpPolicyService;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFollowUpMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        private readonly int $leadId,
    ) {
        $this->onQueue('low');
    }

    public function handle(
        AgentOrchestrator    $orchestrator,
        DelayPolicyService   $delayPolicy,
        OutboundDispatchService $dispatch,
        FollowUpPolicyService $followUpPolicy,
    ): void {
        $lead = Lead::with(['memory', 'whatsappAgent'])->find($this->leadId);

        if (! $lead || $lead->automation_paused) {
            return;
        }

        // Re-check eligibility at dispatch time (state may have changed)
        $check = $followUpPolicy->canSendFollowUp($lead);
        if (! $check->eligible) {
            Log::info('[SendFollowUpMessageJob] No longer eligible at dispatch time', [
                'lead_id' => $lead->id,
                'reason'  => $check->reason,
            ]);
            return;
        }

        $agent = $lead->whatsappAgent;
        if (! $agent || ! $agent->isConnected()) {
            Log::warning('[SendFollowUpMessageJob] Agent not available', ['lead_id' => $lead->id]);
            return;
        }

        $text = $orchestrator->generateFollowUp($lead);
        if (! $text) {
            Log::warning('[SendFollowUpMessageJob] Empty follow-up text generated', ['lead_id' => $lead->id]);
            return;
        }

        $delay = $delayPolicy->getDelay($text);

        $dispatch->queueSend(
            agent:        $agent,
            to:           $lead->preferredWhatsAppRecipient(),
            content:      $text,
            queue:        'low',
            delaySeconds: $delay,
            idempotencyKey: sprintf('follow-up-%d-%d', $lead->id, $check->followUpNumber),
        );

        $followUpPolicy->recordFollowUpSent($lead);

        Log::info('[SendFollowUpMessageJob] Follow-up queued', [
            'lead_id'  => $lead->id,
            'fu_count' => $check->followUpNumber,
        ]);
    }
}
