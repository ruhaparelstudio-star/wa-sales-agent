<?php

namespace App\Modules\Subscription\Services;

use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Exceptions\SubscriptionException;
use App\Modules\Tenancy\Models\Tenant;

class SubscriptionEnforcementService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AgentSlotPolicyService $slotPolicyService,
    ) {}

    public function canAddAgent(Tenant $tenant): bool
    {
        $sub = $this->subscriptionService->getActiveSub($tenant);

        if (! $sub || ! $sub->isActive()) {
            return false;
        }

        return $this->slotPolicyService->isSlotAvailable($tenant);
    }

    public function assertCanSendOutbound(Tenant $tenant): void
    {
        $sub = $this->subscriptionService->getActiveSub($tenant);

        if (! $sub) {
            throw new SubscriptionException('No active subscription found.');
        }

        if ($sub->status === SubscriptionStatus::Expired || $sub->status === SubscriptionStatus::Suspended) {
            throw new SubscriptionException('Subscription is ' . $sub->status->value . '. Outbound messages are blocked.');
        }
    }

    public function getConnectedAgentCount(Tenant $tenant): int
    {
        return $this->slotPolicyService->getUsedSlots($tenant);
    }
}
