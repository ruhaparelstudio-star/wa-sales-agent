<?php

namespace Database\Factories;

use App\Modules\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'name'                    => $this->faker->words(2, true),
            'slug'                    => $this->faker->unique()->slug(2),
            'max_agents'              => $this->faker->numberBetween(1, 10),
            'monthly_token_soft_cap'  => 1500000,
            'features'                => null,
            'price'                   => $this->faker->randomElement([299000, 599000, 999000]),
            'is_active'               => true,
            'sort_order'              => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
