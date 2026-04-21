<?php

namespace Database\Factories;

use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'plan_id'       => SubscriptionPlan::factory(),
            'status'        => SubscriptionStatus::Active,
            'starts_at'     => now()->subMonth(),
            'ends_at'       => now()->addMonth(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addMonth(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status'    => SubscriptionStatus::Expired,
            'starts_at' => now()->subMonths(2),
            'ends_at'   => now()->subDays(10),
        ]);
    }

    public function gracePeriod(): static
    {
        return $this->state([
            'status'        => SubscriptionStatus::GracePeriod,
            'starts_at'     => now()->subMonths(2),
            'ends_at'       => now()->subDays(3),
            'grace_ends_at' => now()->addDays(4),
        ]);
    }

    public function pendingPayment(): static
    {
        return $this->state([
            'status'    => SubscriptionStatus::PendingPayment,
            'starts_at' => now(),
            'ends_at'   => now()->addMonth(),
        ]);
    }
}
