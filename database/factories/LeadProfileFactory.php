<?php

namespace Database\Factories;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadProfileFactory extends Factory
{
    protected $model = LeadProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'lead_id'        => Lead::factory(),
            'event_date'     => $this->faker->dateTimeBetween('+1 month', '+12 months')->format('Y-m-d'),
            'event_location' => $this->faker->city(),
            'budget_min'     => $this->faker->numberBetween(10_000_000, 50_000_000),
            'budget_max'     => $this->faker->numberBetween(50_000_001, 200_000_000),
            'service_type'   => $this->faker->randomElement(['foto', 'video', 'foto+video', 'dekorasi']),
            'guest_count'    => $this->faker->numberBetween(50, 500),
            'notes'          => $this->faker->optional()->sentence(),
        ];
    }
}
