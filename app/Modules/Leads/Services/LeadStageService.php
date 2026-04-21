<?php

namespace App\Modules\Leads\Services;

use App\Modules\Dashboard\Notifications\HotLeadAlertNotification;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;

class LeadStageService
{
    private LeadService $leadService;

    public function __construct(LeadService $leadService)
    {
        $this->leadService = $leadService;
    }

    public function advanceStage(Lead $lead, LeadStatus $newStatus): void
    {
        $this->leadService->updateStatus($lead, $newStatus);

        if (in_array($newStatus, [LeadStatus::Hot, LeadStatus::ReadyForHuman], true)) {
            $this->dispatchHotLeadAlert($lead);
        }
    }

    public function shouldHandoff(Lead $lead, string $intent): bool
    {
        $handoffIntents = [
            'availability_check',
            'custom_package',
            'ready_to_book',
            'payment_proof',
            'complaint',
            'negative_sentiment',
        ];

        return in_array($intent, $handoffIntents, true);
    }

    private function dispatchHotLeadAlert(Lead $lead): void
    {
        $notification = new HotLeadAlertNotification($lead);

        $lead->tenant
            ->tenantUsers()
            ->where('role', 'vendor_admin')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->each(fn ($admin) => $admin->notify($notification));
    }
}
