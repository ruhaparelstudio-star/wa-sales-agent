<?php

namespace App\Modules\AgentCore\Jobs;

use App\Modules\AgentCore\Services\GuardrailService;
use App\Modules\AgentCore\Services\FollowUpPolicyService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FollowUpSchedulerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(FollowUpPolicyService $policy, GuardrailService $guardrail): void
    {
        $excludedStatuses = [
            LeadStatus::ClosedWon->value,
            LeadStatus::ClosedLost->value,
            LeadStatus::ReadyForHuman->value,
        ];

        // Pull candidates: not paused, not terminal, had contact 18+ hours ago
        $leads = Lead::with(['memory', 'whatsappAgent', 'conversations' => fn ($q) => $q->active()->latest()])
            ->where('automation_paused', false)
            ->whereNotIn('status', $excludedStatuses)
            ->where('last_message_at', '<=', now()->subHours(18))
            ->get();

        // Chunk to spread load and avoid burst dispatching
        $leads->chunk(10)->each(function ($chunk, int $chunkIndex) use ($policy, $guardrail) {
            $delaySeconds = $chunkIndex * 30;

            foreach ($chunk as $lead) {
                $check = $policy->canSendFollowUp($lead);
                if (! $check->eligible) {
                    continue;
                }

                $conv = $lead->conversations->first();
                if (! $conv) {
                    continue;
                }

                $guard = $guardrail->check($lead, $conv);
                if ($guard->blocked) {
                    Log::info('[FollowUpScheduler] Guardrail blocked follow-up', [
                        'lead_id' => $lead->id,
                        'reason'  => $guard->reason,
                    ]);
                    continue;
                }

                SendFollowUpMessageJob::dispatch($lead->id)
                    ->onQueue('low')
                    ->delay(now()->addSeconds($delaySeconds));
            }
        });
    }
}
