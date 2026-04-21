<?php

namespace App\Modules\Subscription\Services;

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\Tenancy\Models\Tenant;

class AgentSlotPolicyService
{
    public function __construct(
        private readonly WhatsAppAgentCountContract $agentCounter,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function getUsedSlots(Tenant $tenant): int
    {
        return $this->agentCounter->getConnectedCount($tenant->id);
    }

    public function getRemainingSlots(Tenant $tenant): int
    {
        $sub = $this->subscriptionService->getActiveSub($tenant);

        if (! $sub) {
            return 0;
        }

        return max(0, $sub->plan->max_agents - $this->getUsedSlots($tenant));
    }

    public function isSlotAvailable(Tenant $tenant): bool
    {
        return $this->getRemainingSlots($tenant) > 0;
    }
}
