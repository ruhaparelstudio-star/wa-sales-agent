<?php

namespace Database\Factories;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationStateFactory extends Factory
{
    protected $model = ConversationState::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'conversation_id' => Conversation::factory(),
            'lead_id' => Lead::factory(),
            'current_stage' => 'new_lead',
            'current_intent' => 'greeting',
            'intent_confidence' => 0.95,
            'interpretation_source' => 'rules',
            'lead_temperature' => 'cold',
            'filled_slots' => [
                'event_type' => null,
                'name' => null,
                'event_date' => null,
                'event_time_start' => null,
                'event_time_end' => null,
                'location' => null,
                'service_type' => null,
                'guest_count' => null,
                'budget' => null,
                'package_interest' => null,
                'payment_topic' => null,
                'inquiry_fields' => [],
                'booking_fields' => [],
            ],
            'unresolved_questions' => [],
            'last_user_message' => null,
            'last_agent_message' => null,
            'last_agent_question' => null,
            'last_answered_topic' => null,
            'next_best_action' => 'respond_to_user',
            'last_tool_result_summary' => null,
        ];
    }
}
