<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Jobs\ProcessInboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\WebhookIngressService;
use App\Modules\WhatsApp\Services\WhatsAppAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;


function makeIngressService(): WebhookIngressService
{
    return new WebhookIngressService(new WhatsAppAgentService());
}

test('idempotency key prevents duplicate event processing', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'message_received',
        'agent_id' => $agent->id,
        'data' => [
            'wa_message_id' => 'abc123',
            'from' => '+6281234567890',
            'type' => 'text',
            'content' => 'Hello',
            'timestamp' => now()->toISOString(),
        ],
    ];

    $service = makeIngressService();
    $service->handle($payload, 'idempotency-key-001');
    $service->handle($payload, 'idempotency-key-001'); // duplicate

    Queue::assertPushed(ProcessInboundMessageJob::class, 1);
});

test('message_received event dispatches ProcessInboundMessageJob on high queue', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'message_received',
        'agent_id' => $agent->id,
        'data' => [
            'wa_message_id' => 'xyz789',
            'from' => '+6281234567890',
            'from_jid' => '6281234567890@s.whatsapp.net',
            'type' => 'text',
            'content' => 'Test message',
            'timestamp' => now()->toISOString(),
        ],
    ];

    makeIngressService()->handle($payload, 'unique-key-001');

    Queue::assertPushedOn('high', ProcessInboundMessageJob::class);
    Queue::assertPushed(ProcessInboundMessageJob::class, function (ProcessInboundMessageJob $job) {
        return $job->fromJid === '6281234567890@s.whatsapp.net';
    });
});

test('message_received forwards quoted context into inbound job payload', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'message_received',
        'agent_id' => $agent->id,
        'data' => [
            'wa_message_id' => 'quoted-xyz789',
            'from' => '+6281234567890',
            'from_jid' => '6281234567890@s.whatsapp.net',
            'type' => 'text',
            'content' => 'Yang ini ya',
            'quoted_wa_message_id' => 'quoted-parent-001',
            'quoted_from_jid' => '628100000001@s.whatsapp.net',
            'quoted_content' => 'Bisa share pricelist?',
            'timestamp' => now()->toISOString(),
        ],
    ];

    makeIngressService()->handle($payload, 'quoted-key-001');

    Queue::assertPushed(ProcessInboundMessageJob::class, function (ProcessInboundMessageJob $job) {
        return $job->quotedWaMessageId === 'quoted-parent-001'
            && $job->quotedFromJid === '628100000001@s.whatsapp.net'
            && $job->quotedContent === 'Bisa share pricelist?'
            && $job->webhookIdempotencyKey === 'quoted-key-001';
    });
});

test('message_received from self does not dispatch inbound processing', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'message_received',
        'agent_id' => $agent->id,
        'data' => [
            'wa_message_id' => 'self-001',
            'from' => '+6281234567890',
            'type' => 'text',
            'content' => 'Pesan dari agent sendiri',
            'timestamp' => now()->toISOString(),
            'is_from_me' => true,
        ],
    ];

    makeIngressService()->handle($payload, 'unique-key-self-001');

    Queue::assertNotPushed(ProcessInboundMessageJob::class);
});

test('agent_connected event updates agent status', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'agent_connected',
        'agent_id' => $agent->id,
        'data' => ['phone_number' => '+6281111222333', 'connected_at' => now()->toISOString()],
    ];

    makeIngressService()->handle($payload, 'unique-key-002');

    expect($agent->fresh()->status)->toBe(AgentStatus::Connected);
});

test('agent_disconnected event updates agent status', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'agent_disconnected',
        'agent_id' => $agent->id,
        'data' => ['reason' => 'connection_lost', 'disconnected_at' => now()->toISOString()],
    ];

    makeIngressService()->handle($payload, 'unique-key-003');

    expect($agent->fresh()->status)->toBe(AgentStatus::Disconnected);
});

test('different idempotency keys are each processed independently', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'event' => 'message_received',
        'agent_id' => $agent->id,
        'data' => [
            'wa_message_id' => 'msg-a',
            'from' => '+6281234567890',
            'type' => 'text',
            'content' => 'Hello',
            'timestamp' => now()->toISOString(),
        ],
    ];

    $service = makeIngressService();
    $service->handle($payload, 'key-A');
    $service->handle($payload, 'key-B');

    Queue::assertPushed(ProcessInboundMessageJob::class, 2);
});
