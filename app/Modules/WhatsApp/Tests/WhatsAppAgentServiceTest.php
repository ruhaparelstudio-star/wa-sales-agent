<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use App\Modules\WhatsApp\Services\WhatsAppAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;


test('handleAgentConnected updates status and completes pairing', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);
    $pairing = WhatsAppPairing::factory()->pending()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $service = new WhatsAppAgentService();
    $service->handleAgentConnected($agent->id, '+6281234567890');

    $agent->refresh();
    $pairing->refresh();

    expect($agent->status)->toBe(AgentStatus::Connected)
        ->and($agent->phone_number)->toBe('+6281234567890')
        ->and($agent->last_connected_at)->not->toBeNull()
        ->and($pairing->status)->toBe(PairingStatus::Completed)
        ->and($pairing->completed_at)->not->toBeNull();
});

test('handleAgentConnected disconnects conflicting agent with same phone number', function () {
    $tenant = Tenant::factory()->create();
    $existing = WhatsAppAgent::factory()->connected()->create([
        'tenant_id' => $tenant->id,
        'phone_number' => '+6281234567890',
        'is_default' => true,
    ]);
    $replacement = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);

    $service = new WhatsAppAgentService();
    $service->handleAgentConnected($replacement->id, '+6281234567890');

    expect($replacement->fresh()->status)->toBe(AgentStatus::Connected)
        ->and($replacement->fresh()->phone_number)->toBe('+6281234567890')
        ->and($existing->fresh()->status)->toBe(AgentStatus::Disconnected)
        ->and($existing->fresh()->phone_number)->toBeNull()
        ->and($existing->fresh()->is_default)->toBeFalse();
});

test('handleAgentDisconnected updates status correctly', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $service = new WhatsAppAgentService();
    $service->handleAgentDisconnected($agent->id, 'connection_lost');

    $agent->refresh();

    expect($agent->status)->toBe(AgentStatus::Disconnected)
        ->and($agent->last_disconnected_at)->not->toBeNull();
});

test('disconnected agent does not appear in connected scope', function () {
    $tenant = Tenant::factory()->create();
    WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);

    $connected = WhatsAppAgent::forTenant($tenant->id)->connected()->count();

    expect($connected)->toBe(1);
});

test('setDefaultAgent sets only one default per tenant', function () {
    $tenant = Tenant::factory()->create();
    $agent1 = WhatsAppAgent::factory()->connected()->default()->create(['tenant_id' => $tenant->id]);
    $agent2 = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $service = new WhatsAppAgentService();
    $service->setDefaultAgent($tenant, $agent2);

    expect($agent1->fresh()->is_default)->toBeFalse()
        ->and($agent2->fresh()->is_default)->toBeTrue();
});

test('getDefaultAgent returns the connected default agent', function () {
    $tenant = Tenant::factory()->create();
    $default = WhatsAppAgent::factory()->connected()->default()->create(['tenant_id' => $tenant->id]);
    WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $service = new WhatsAppAgentService();
    $found = $service->getDefaultAgent($tenant);

    expect($found->id)->toBe($default->id);
});
