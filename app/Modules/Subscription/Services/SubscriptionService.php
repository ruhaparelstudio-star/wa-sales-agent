<?php

namespace App\Modules\Subscription\Services;

use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Tenancy\Models\Tenant;

class SubscriptionService
{
    public function getActiveSub(Tenant $tenant): ?Subscription
    {
        return Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::GracePeriod])
            ->latest()
            ->first();
    }

    public function assignPlan(Tenant $tenant, SubscriptionPlan $plan, int $trialDays = 0): Subscription
    {
        if ($trialDays > 0) {
            return Subscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'status'        => SubscriptionStatus::Active,
                'starts_at'     => now(),
                'ends_at'       => now()->addDays($trialDays),
                'trial_ends_at' => now()->addDays($trialDays),
            ]);
        }

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
            'status'    => SubscriptionStatus::PendingPayment,
            'starts_at' => now(),
            'ends_at'   => now()->addMonth(),
        ]);
    }

    public function renewSubscription(Subscription $sub): Subscription
    {
        $newStart = $sub->ends_at->isFuture() ? $sub->ends_at : now();

        $sub->starts_at     = $newStart;
        $sub->ends_at       = $newStart->copy()->addMonth();
        $sub->status        = SubscriptionStatus::Active;
        $sub->grace_ends_at = null;
        $sub->save();

        return $sub;
    }

    public function refreshStatus(Subscription $sub): Subscription
    {
        if ($sub->status === SubscriptionStatus::Suspended) {
            return $sub;
        }

        $now = now();

        if ($now->lessThanOrEqualTo($sub->ends_at)) {
            $sub->status = SubscriptionStatus::Active;
        } elseif ($sub->grace_ends_at && $now->lessThanOrEqualTo($sub->grace_ends_at)) {
            $sub->status = SubscriptionStatus::GracePeriod;
        } else {
            $sub->status = SubscriptionStatus::Expired;
        }

        $sub->save();

        return $sub;
    }
}
