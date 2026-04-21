<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsAppAgentFactory extends Factory
{
    protected $model = WhatsAppAgent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'phone_number' => '+628' . $this->faker->numerify('#########'),
            'display_name' => $this->faker->company(),
            'status' => AgentStatus::Connected,
            'is_default' => false,
            'last_connected_at' => now(),
            'last_disconnected_at' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state(['status' => AgentStatus::Connected, 'last_connected_at' => now()]);
    }

    public function disconnected(): static
    {
        return $this->state([
            'status' => AgentStatus::Disconnected,
            'last_disconnected_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'status' => AgentStatus::Pending,
            'phone_number' => null,
            'last_connected_at' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
