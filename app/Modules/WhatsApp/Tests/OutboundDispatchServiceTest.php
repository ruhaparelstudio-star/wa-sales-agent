<?php

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationLockService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppOutboundDispatch;
use App\Modules\WhatsApp\Services\DuplicateMessageGuardService;
use App\Modules\WhatsApp\Services\OutboundDispatchService;

test('outbound send is idempotent for the same idempotency key', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
        'phone_e164' => '+6281234567890',
        'whatsapp_jid' => '6281234567890@s.whatsapp.net',
    ]);
    Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $provider = Mockery::mock(WhatsAppProviderInterface::class);
    $provider->shouldReceive('sendMessage')
        ->once()
        ->with($agent->id, '6281234567890@s.whatsapp.net', 'Halo dari AI', 'outbound-key-001')
        ->andReturn(['message_id' => 'wamid-outbound-001']);

    $service = new OutboundDispatchService(
        $provider,
        app(DuplicateMessageGuardService::class),
        new ConversationLockService(),
    );

    $service->send($agent, '6281234567890@s.whatsapp.net', 'Halo dari AI', 'outbound-key-001');
    $service->send($agent, '6281234567890@s.whatsapp.net', 'Halo dari AI', 'outbound-key-001');

    expect(Message::query()->where('direction', 'outbound')->count())->toBe(1)
        ->and(Message::query()->where('provider_idempotency_key', 'outbound-key-001')->count())->toBe(1)
        ->and(WhatsAppOutboundDispatch::query()->count())->toBe(1)
        ->and(WhatsAppOutboundDispatch::query()->first()?->provider_message_id)->toBe('wamid-outbound-001');
});
