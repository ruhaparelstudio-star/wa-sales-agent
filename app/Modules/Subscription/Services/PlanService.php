<?php

namespace App\Modules\Subscription\Services;

use App\Modules\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PlanService
{
    public function getActivePlans(): Collection
    {
        return SubscriptionPlan::active()->get();
    }

    public function getPlanById(int $id): SubscriptionPlan
    {
        return SubscriptionPlan::findOrFail($id);
    }

    public function getPlanFeatures(SubscriptionPlan $plan): array
    {
        return $plan->features ?? [];
    }
}
