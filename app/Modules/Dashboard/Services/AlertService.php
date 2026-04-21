<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;

class AlertService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * Returns subscription alert level: null | 'warning' | 'danger' | 'critical'
     */
    public function getSubscriptionAlertLevel(Tenant $tenant): ?string
    {
        $sub = $this->subscriptionService->getActiveSub($tenant);

        if ($sub === null) {
            return 'critical';
        }

        if ($sub->isExpired()) {
            return 'critical';
        }

        if ($sub->isInGracePeriod()) {
            return 'danger';
        }

        if ($sub->daysUntilExpiry() <= 7) {
            return 'warning';
        }

        return null;
    }

    public function getSubscriptionAlertMessage(Tenant $tenant): ?string
    {
        $level = $this->getSubscriptionAlertLevel($tenant);
        $sub   = $this->subscriptionService->getActiveSub($tenant);

        return match ($level) {
            'critical' => 'Subscription has expired. Outbound messaging is disabled.',
            'danger'   => 'Subscription is in grace period. Please renew immediately.',
            'warning'  => "Subscription expires in {$sub?->daysUntilExpiry()} days. Please renew.",
            default    => null,
        };
    }
}
