<?php

namespace Database\Factories;

use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeCandidate;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeCandidateFactory extends Factory
{
    protected $model = KnowledgeCandidate::class;

    public function definition(): array
    {
        return [
            'tenant_id'        => Tenant::factory(),
            'conversation_id'  => null,
            'proposed_title'   => $this->faker->sentence(4),
            'proposed_content' => $this->faker->paragraph(),
            'proposed_type'    => KnowledgeType::Faq->value,
            'source_note'      => null,
            'status'           => KnowledgeStatus::Pending,
            'reviewed_by'      => null,
            'reviewed_at'      => null,
            'promoted_to_item_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => KnowledgeStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(['status' => KnowledgeStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => KnowledgeStatus::Rejected]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
