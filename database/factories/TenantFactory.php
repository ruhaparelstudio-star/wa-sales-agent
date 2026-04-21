<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->firstName() . ' ' . $this->faker->lastName() . ' Wedding';

        return [
            'name'      => $name,
            'slug'      => Str::slug($name) . '-' . Str::lower($this->faker->lexify('?????')),
            'is_active' => true,
            'settings'  => [
                'quiet_hours_start' => '22:00',
                'quiet_hours_end'   => '07:00',
                'follow_up_max'     => 2,
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withQuietHours(string $start, string $end): static
    {
        return $this->state(fn (array $attrs) => [
            'settings' => array_merge($attrs['settings'] ?? [], [
                'quiet_hours_start' => $start,
                'quiet_hours_end'   => $end,
            ]),
        ]);
    }
}
