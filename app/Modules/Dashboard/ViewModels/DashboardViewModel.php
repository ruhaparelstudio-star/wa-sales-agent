<?php

namespace App\Modules\Dashboard\ViewModels;

use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Dashboard\Services\AlertService;
use App\Modules\Dashboard\Services\DashboardMetricsService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;

class DashboardViewModel
{
    public function __construct(
        private readonly DashboardMetricsService $metricsService,
        private readonly AlertService $alertService,
    ) {}

    public function forTenant(Tenant $tenant): array
    {
        return [
            'metrics'        => $this->metricsService->getMetrics($tenant),
            'alert_level'    => $this->alertService->getSubscriptionAlertLevel($tenant),
            'alert_message'  => $this->alertService->getSubscriptionAlertMessage($tenant),
            'hot_leads'      => $this->recentHotLeads($tenant),
            'pending_handoffs' => $this->recentPendingHandoffs($tenant),
        ];
    }

    private function recentHotLeads(Tenant $tenant)
    {
        return Lead::forTenant($tenant->id)
            ->whereIn('status', [LeadStatus::Hot->value, LeadStatus::ReadyForHuman->value])
            ->with('whatsappAgent')
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();
    }

    private function recentPendingHandoffs(Tenant $tenant)
    {
        return HandoffRequest::where('tenant_id', $tenant->id)
            ->where('status', HandoffStatus::Pending->value)
            ->with('lead')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }
}
