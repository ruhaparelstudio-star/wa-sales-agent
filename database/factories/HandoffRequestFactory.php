<?php

namespace Database\Factories;

use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class HandoffRequestFactory extends Factory
{
    protected $model = HandoffRequest::class;

    public function definition(): array
    {
        return [
            'tenant_id'         => Tenant::factory(),
            'lead_id'           => Lead::factory(),
            'conversation_id'   => Conversation::factory(),
            'reason'            => $this->faker->randomElement(HandoffReason::cases()),
            'reason_detail'     => $this->faker->optional()->sentence(),
            'status'            => HandoffStatus::Pending,
            'resolved_by'       => null,
            'resolved_at'       => null,
            'summary_for_admin' => $this->faker->optional()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => HandoffStatus::Pending]);
    }

    public function resolved(): static
    {
        return $this->state([
            'status'      => HandoffStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state([
            'status'      => HandoffStatus::Dismissed,
            'resolved_at' => now(),
        ]);
    }
}
