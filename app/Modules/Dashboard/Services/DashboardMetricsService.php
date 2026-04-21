<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\Cache;

class DashboardMetricsService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AgentSlotPolicyService $slotPolicy,
    ) {}

    public function getMetrics(Tenant $tenant): array
    {
        return Cache::remember("dashboard_metrics_{$tenant->id}", 2100, function () use ($tenant) {
            $sub = $this->subscriptionService->getActiveSub($tenant);

            return [
                'hot_leads_count' => Lead::forTenant($tenant->id)
                    ->whereIn('status', [LeadStatus::Hot->value, LeadStatus::ReadyForHuman->value])
                    ->count(),

                'pending_handoffs_count' => HandoffRequest::where('tenant_id', $tenant->id)
                    ->where('status', HandoffStatus::Pending->value)
                    ->count(),

                'connected_agents_count' => WhatsAppAgent::forTenant($tenant->id)
                    ->where('status', AgentStatus::Connected->value)
                    ->count(),

                'agent_slots_used' => $this->slotPolicy->getUsedSlots($tenant),
                'agent_slots_max' => $sub?->plan->max_agents ?? 0,

                'subscription_days_remaining' => $sub?->daysUntilExpiry() ?? 0,

                'unpaid_billing_count' => BillingInvoice::forTenant($tenant->id)
                    ->where('status', BillingInvoiceStatus::Unpaid->value)
                    ->count(),

                'total_leads_today' => Lead::forTenant($tenant->id)
                    ->whereDate('created_at', today())
                    ->count(),
            ];
        });
    }

    public function flushCache(int $tenantId): void
    {
        Cache::forget("dashboard_metrics_{$tenantId}");
    }
}
