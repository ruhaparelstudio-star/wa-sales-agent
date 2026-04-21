<?php

namespace Database\Factories;

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeItemFactory extends Factory
{
    protected $model = KnowledgeItem::class;

    public function definition(): array
    {
        return [
            'tenant_id'  => Tenant::factory(),
            'type'       => KnowledgeType::Faq,
            'title'      => $this->faker->sentence(4),
            'content'    => $this->faker->paragraph(),
            'tags'       => null,
            'is_active'  => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function faq(): static
    {
        return $this->state(['type' => KnowledgeType::Faq]);
    }

    public function package(): static
    {
        return $this->state(['type' => KnowledgeType::Package]);
    }

    public function policy(): static
    {
        return $this->state(['type' => KnowledgeType::Policy]);
    }

    public function portfolio(): static
    {
        return $this->state(['type' => KnowledgeType::Portfolio]);
    }

    public function objection(): static
    {
        return $this->state(['type' => KnowledgeType::Objection]);
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
