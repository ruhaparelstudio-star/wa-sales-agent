<?php

namespace App\Modules\Conversations\Services;

use App\Models\User;
use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\Auth\Services\TenantMembershipService;
use App\Modules\Dashboard\Notifications\HandoffCreatedNotification;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HandoffRequestService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly LeadService         $leadService,
        private readonly LeadStageService    $leadStageService,
    ) {}

    public function create(
        Lead $lead,
        Conversation $conv,
        HandoffReason $reason,
        ?string $detail = null,
        ?string $summaryForAdmin = null,
    ): HandoffRequest {
        $request = HandoffRequest::create([
            'tenant_id'         => $lead->tenant_id,
            'lead_id'           => $lead->id,
            'conversation_id'   => $conv->id,
            'reason'            => $reason,
            'reason_detail'     => $detail,
            'status'            => HandoffStatus::Pending,
            'summary_for_admin' => $summaryForAdmin,
        ]);

        // Mark conversation as handoff
        $this->conversationService->markHandoff($conv);

        // Advance lead stage to READY_FOR_HUMAN
        $this->leadStageService->advanceStage($lead, LeadStatus::ReadyForHuman);

        // Pause automation
        $this->leadService->pauseAutomation($lead);

        Log::info('[HandoffRequest] Created', [
            'tenant_id'   => $lead->tenant_id,
            'lead_id'     => $lead->id,
            'handoff_id'  => $request->id,
            'reason'      => $reason->value,
        ]);

        $this->notifyAdmins($request, $lead);

        return $request;
    }

    public function resolve(HandoffRequest $req, User $user): void
    {
        $req->update([
            'status'      => HandoffStatus::Resolved,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        $lead = $req->lead;

        // Resume AI automation
        $this->leadService->resumeAutomation($lead);

        // Return conversation to active so AI can respond again
        $conv = $req->conversation;
        if ($conv && $conv->status !== \App\Modules\Conversations\Enums\ConversationStatus::Closed) {
            $this->conversationService->markActive($conv);
        }

        Log::info('[HandoffRequest] Resolved — AI resumed', [
            'tenant_id'  => $req->tenant_id,
            'lead_id'    => $lead->id,
            'handoff_id' => $req->id,
            'resolved_by'=> $user->id,
        ]);
    }

    public function dismiss(HandoffRequest $req, User $user): void
    {
        $req->update([
            'status'      => HandoffStatus::Dismissed,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);
    }

    private function notifyAdmins(HandoffRequest $handoff, Lead $lead): void
    {
        $admins = $lead->tenant
            ->tenantUsers()
            ->where('role', 'vendor_admin')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($admins as $admin) {
            $admin->notify(new HandoffCreatedNotification($handoff));
        }
    }

    public function getPendingForTenant(Tenant $tenant): Collection
    {
        return HandoffRequest::forTenant($tenant->id)->pending()->latest()->get();
    }
}
