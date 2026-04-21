<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WhatsAppPairingFactory extends Factory
{
    protected $model = WhatsAppPairing::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'whatsapp_agent_id' => null,
            'status' => PairingStatus::Pending,
            'pairing_token' => Str::random(64),
            'expires_at' => now()->addMinutes(10),
            'completed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => PairingStatus::Pending, 'expires_at' => now()->addMinutes(10)]);
    }

    public function completed(): static
    {
        return $this->state(['status' => PairingStatus::Completed, 'completed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PairingStatus::Cancelled, 'cancelled_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['status' => PairingStatus::Expired, 'expires_at' => now()->subHour()]);
    }
}
