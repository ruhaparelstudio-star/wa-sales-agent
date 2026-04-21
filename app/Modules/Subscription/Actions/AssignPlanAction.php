<?php

namespace App\Modules\Subscription\Actions;

use App\Modules\Subscription\DTOs\AssignPlanDTO;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

class AssignPlanAction
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function execute(AssignPlanDTO $dto): Subscription
    {
        $plan = SubscriptionPlan::findOrFail($dto->planId);

        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan_id' => 'The selected plan is not active.',
            ]);
        }

        $tenant = Tenant::findOrFail($dto->tenantId);

        return $this->subscriptionService->assignPlan($tenant, $plan, $dto->trialDays);
    }
}
