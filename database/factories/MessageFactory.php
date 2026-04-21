<?php

namespace Database\Factories;

use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Enums\MessageStatus;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'tenant_id'       => Tenant::factory(),
            'conversation_id' => Conversation::factory(),
            'lead_id'         => Lead::factory(),
            'direction'       => MessageDirection::Inbound,
            'message_type'    => MessageType::Text,
            'content'         => $this->faker->sentence(),
            'media_url'       => null,
            'media_mime'      => null,
            'media_filename'  => null,
            'wa_message_id'   => $this->faker->uuid(),
            'provider_idempotency_key' => null,
            'quoted_wa_message_id' => null,
            'quoted_from_jid' => null,
            'quoted_content' => null,
            'status'          => MessageStatus::Delivered,
            'is_from_ai'      => false,
        ];
    }

    public function inbound(): static
    {
        return $this->state(['direction' => MessageDirection::Inbound]);
    }

    public function outbound(): static
    {
        return $this->state(['direction' => MessageDirection::Outbound, 'status' => MessageStatus::Sent]);
    }

    public function fromAi(): static
    {
        return $this->state(['direction' => MessageDirection::Outbound, 'is_from_ai' => true, 'status' => MessageStatus::Sent]);
    }

    public function image(): static
    {
        return $this->state([
            'message_type'  => MessageType::Image,
            'content'       => null,
            'media_url'     => 'https://example.com/media/test.jpg',
            'media_mime'    => 'image/jpeg',
            'media_filename'=> 'test.jpg',
        ]);
    }

    public function document(): static
    {
        return $this->state([
            'message_type'  => MessageType::Document,
            'content'       => null,
            'media_url'     => 'https://example.com/media/doc.pdf',
            'media_mime'    => 'application/pdf',
            'media_filename'=> 'bukti_transfer.pdf',
        ]);
    }
}
