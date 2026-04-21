<?php

namespace Database\Factories;

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'tenant_id'         => Tenant::factory(),
            'whatsapp_agent_id' => null,
            'phone_e164'        => '+628' . $this->faker->numerify('#########'),
            'name'              => $this->faker->name(),
            'status'            => LeadStatus::New,
            'interest_score'    => 0,
            'risk_score'        => 0,
            'automation_paused' => false,
            'last_message_at'   => null,
        ];
    }

    public function withNewStatus(): static
    {
        return $this->state(['status' => LeadStatus::New]);
    }

    public function qualified(): static
    {
        return $this->state(['status' => LeadStatus::Qualified]);
    }

    public function interested(): static
    {
        return $this->state(['status' => LeadStatus::Interested]);
    }

    public function hot(): static
    {
        return $this->state(['status' => LeadStatus::Hot]);
    }

    public function readyForHuman(): static
    {
        return $this->state(['status' => LeadStatus::ReadyForHuman]);
    }

    public function closedWon(): static
    {
        return $this->state(['status' => LeadStatus::ClosedWon]);
    }

    public function closedLost(): static
    {
        return $this->state(['status' => LeadStatus::ClosedLost]);
    }

    public function paused(): static
    {
        return $this->state(['automation_paused' => true]);
    }

    public function withAgent(WhatsAppAgent $agent): static
    {
        return $this->state([
            'tenant_id'         => $agent->tenant_id,
            'whatsapp_agent_id' => $agent->id,
        ]);
    }
}
