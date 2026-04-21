<?php

namespace Database\Factories;

use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id'              => Tenant::factory(),
            'lead_id'                => Lead::factory(),
            'whatsapp_agent_id'      => null,
            'status'                 => ConversationStatus::Active,
            'stage'                  => ConversationStage::NewLead,
            'stage_updated_at'       => null,
            'asked_fields'           => [],
            'next_expected_field'    => null,
            'stage_transition_count' => 0,
            'is_human_takeover'      => false,
            'closed_at'              => null,
        ];
    }

    public function atStage(ConversationStage $stage): static
    {
        return $this->state([
            'stage'            => $stage,
            'stage_updated_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->state(['status' => ConversationStatus::Active, 'closed_at' => null]);
    }

    public function closed(): static
    {
        return $this->state(['status' => ConversationStatus::Closed, 'closed_at' => now()]);
    }

    public function handoff(): static
    {
        return $this->state(['status' => ConversationStatus::Handoff]);
    }

    public function humanTakeover(): static
    {
        return $this->state(['is_human_takeover' => true]);
    }
}
