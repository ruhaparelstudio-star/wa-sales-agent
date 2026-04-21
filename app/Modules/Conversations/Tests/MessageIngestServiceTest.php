<?php

use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\MessageIngestService;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeMessageIngestService(): MessageIngestService
{
    return new MessageIngestService(new LeadService(), new ConversationService());
}

function makeWebhookData(string $agentId, string $phone, array $overrides = []): array
{
    return array_merge([
        'agent_id'      => $agentId,
        'from'          => $phone,
        'from_jid'      => ltrim($phone, '+') . '@s.whatsapp.net',
        'type'          => 'text',
        'content'       => 'Halo, saya ingin tanya harga paket foto',
        'wa_message_id' => 'WAMID_' . uniqid(),
        'media_url'     => null,
        'media_mime'    => null,
    ], $overrides);
}

test('inbound text message is stored with correct tenant, lead, and conversation', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $phone  = '+6281234000001';

    $message = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, $phone));

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->tenant_id)->toBe($tenant->id)
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->message_type)->toBe(MessageType::Text)
        ->and($message->lead->phone_e164)->toBe($phone)
        ->and($message->lead->whatsapp_jid)->toBe('6281234000001@s.whatsapp.net')
        ->and($message->lead->tenant_id)->toBe($tenant->id);
});

test('inbound message stores original whatsapp jid for future replies', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $message = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, '+244529684836573', [
        'from_jid' => '244529684836573@lid',
    ]));

    expect($message->lead->whatsapp_jid)->toBe('244529684836573@lid')
        ->and($message->lead->preferredWhatsAppRecipient())->toBe('244529684836573@lid');
});

test('inbound image message is stored with correct message_type', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $message = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, '+6281234000002', [
        'type'       => 'image',
        'content'    => null,
        'media_url'  => 'https://wa.example.com/media/abc123.jpg',
        'media_mime' => 'image/jpeg',
    ]));

    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->media_url)->not->toBeNull()
        ->and($message->content)->toBeNull();
});

test('ingesting message updates lead last_message_at', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $message = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, '+6281234000003'));

    expect($message->lead->last_message_at)->not->toBeNull();
});

test('two messages from same phone reuse the same lead and conversation', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $phone  = '+6281234000004';

    $msg1 = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, $phone));
    $msg2 = makeMessageIngestService()->ingestInbound(makeWebhookData($agent->id, $phone, [
        'wa_message_id' => 'WAMID_different',
        'content'       => 'Pesan kedua',
    ]));

    expect($msg1->lead_id)->toBe($msg2->lead_id)
        ->and($msg1->conversation_id)->toBe($msg2->conversation_id);
});

test('duplicate inbound whatsapp message id reuses existing message record', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $phone  = '+6281234000005';

    $data = makeWebhookData($agent->id, $phone, [
        'wa_message_id' => 'WAMID_DUPLICATE_001',
        'quoted_wa_message_id' => 'WAMID_QUOTED_123',
        'quoted_from_jid' => '6281234000999@s.whatsapp.net',
        'quoted_content' => 'Boleh info pricelistnya?',
    ]);

    $first = makeMessageIngestService()->ingestInbound($data);
    $second = makeMessageIngestService()->ingestInbound($data);

    expect($first->id)->toBe($second->id)
        ->and(Message::query()->count())->toBe(1)
        ->and($second->quoted_wa_message_id)->toBe('WAMID_QUOTED_123')
        ->and($second->quoted_from_jid)->toBe('6281234000999@s.whatsapp.net')
        ->and($second->quoted_content)->toBe('Boleh info pricelistnya?');
});
