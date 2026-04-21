<?php

namespace Database\Factories;

use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFormTemplateFactory extends Factory
{
    protected $model = BookingFormTemplate::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'form_type' => FormType::Inquiry,
            'name'      => $this->faker->words(3, true),
            'is_active' => true,
        ];
    }

    public function inquiry(): static
    {
        return $this->state(['form_type' => FormType::Inquiry]);
    }

    public function booking(): static
    {
        return $this->state(['form_type' => FormType::Booking]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
