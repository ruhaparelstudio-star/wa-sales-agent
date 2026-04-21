<?php

namespace Database\Factories;

use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFieldFactory extends Factory
{
    protected $model = BookingField::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'template_id' => BookingFormTemplate::factory(),
            'field_key'   => $this->faker->unique()->slug(2),
            'label'       => $this->faker->words(2, true),
            'field_type'  => BookingFieldType::Text,
            'options'     => null,
            'is_required' => false,
            'sort_order'  => $this->faker->numberBetween(0, 100),
        ];
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }

    public function select(array $options = ['Opsi A', 'Opsi B', 'Opsi C']): static
    {
        return $this->state([
            'field_type' => BookingFieldType::Select,
            'options'    => $options,
        ]);
    }

    public function forTemplate(BookingFormTemplate $template): static
    {
        return $this->state([
            'tenant_id'   => $template->tenant_id,
            'template_id' => $template->id,
        ]);
    }
}
