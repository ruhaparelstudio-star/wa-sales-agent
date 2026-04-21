<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\AgentRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;


test('returns assigned agent when it is connected', function () {
    $tenant = Tenant::factory()->create();
    $assigned = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    WhatsAppAgent::factory()->connected()->default()->create(['tenant_id' => $tenant->id]);

    $service = new AgentRoutingService();
    $resolved = $service->resolveAgentForLead($tenant, $assigned->id);

    expect($resolved->id)->toBe($assigned->id);
});

test('falls back to default agent when assigned agent is disconnected', function () {
    $tenant = Tenant::factory()->create();
    $assigned = WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);
    $default = WhatsAppAgent::factory()->connected()->default()->create(['tenant_id' => $tenant->id]);

    $service = new AgentRoutingService();
    $resolved = $service->resolveAgentForLead($tenant, $assigned->id);

    expect($resolved->id)->toBe($default->id);
});

test('falls back to any connected agent when no default', function () {
    $tenant = Tenant::factory()->create();
    WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);
    $any = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id, 'is_default' => false]);

    $service = new AgentRoutingService();
    $resolved = $service->resolveAgentForLead($tenant, null);

    expect($resolved->id)->toBe($any->id);
});

test('throws when no connected agents available', function () {
    $tenant = Tenant::factory()->create();
    WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);

    $service = new AgentRoutingService();

    expect(fn () => $service->resolveAgentForLead($tenant, null))
        ->toThrow(RuntimeException::class);
});

test('resolveAgentByPhone finds connected agent by normalized phone', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create([
        'tenant_id' => $tenant->id,
        'phone_number' => '+6281234567890',
    ]);

    $service = new AgentRoutingService();

    expect($service->resolveAgentByPhone('+6281234567890')?->id)->toBe($agent->id)
        ->and($service->resolveAgentByPhone('6281234567890')?->id)->toBe($agent->id);
});
