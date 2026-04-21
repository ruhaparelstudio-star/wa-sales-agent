<?php

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Exceptions\AgentAlreadyConnectedException;
use App\Modules\WhatsApp\Exceptions\AgentSlotFullException;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\PairingService;
use App\Modules\WhatsApp\Services\WhatsAppAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makePairingService(int $connectedCount = 0, bool $startAgentShouldSucceed = true): PairingService
{
    $mockCounter = Mockery::mock(WhatsAppAgentCountContract::class);
    $mockCounter->shouldReceive('getConnectedCount')->andReturn($connectedCount);

    $mockProvider = Mockery::mock(WhatsAppProviderInterface::class);
    if ($startAgentShouldSucceed) {
        $mockProvider->shouldReceive('startAgent')->andReturn(['status' => 'starting']);
        $mockProvider->shouldReceive('cancelPairing')->andReturnNull();
        $mockProvider->shouldReceive('disconnectAgent')->andReturnNull();
    }

    $slotPolicy = new AgentSlotPolicyService($mockCounter, new SubscriptionService());

    return new PairingService($mockProvider, $slotPolicy, new WhatsAppAgentService());
}

test('initiate pairing fails when no slots available', function () {
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['max_agents' => 1]);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    $service = makePairingService(connectedCount: 1); // already at max

    expect(fn () => $service->initiatePairing($tenant))
        ->toThrow(AgentSlotFullException::class);
});

test('initiate pairing creates pending agent and pairing record', function () {
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['max_agents' => 3]);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    $service = makePairingService(connectedCount: 0);
    $pairing = $service->initiatePairing($tenant);

    expect($pairing->status)->toBe(PairingStatus::Pending)
        ->and($pairing->tenant_id)->toBe($tenant->id)
        ->and($pairing->agent->status)->toBe(AgentStatus::Pending);
});

test('cancel pairing sets status to cancelled', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);
    $pairing = \App\Modules\WhatsApp\Models\WhatsAppPairing::factory()->pending()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $service = makePairingService(connectedCount: 0);
    $service->cancelPairing($pairing);

    expect($pairing->fresh()->status)->toBe(PairingStatus::Cancelled)
        ->and($pairing->fresh()->cancelled_at)->not->toBeNull();
});

test('reconnect rejects connected agent', function () {
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['max_agents' => 3]);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $service = makePairingService(connectedCount: 1);

    expect(fn () => $service->attemptReconnect($tenant, $agent))
        ->toThrow(AgentAlreadyConnectedException::class);
});

test('reconnect succeeds for disconnected agent and slot is counted again', function () {
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['max_agents' => 3]);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    $agent = WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);
    $service = makePairingService(connectedCount: 0);

    $pairing = $service->attemptReconnect($tenant, $agent);

    expect($pairing->status)->toBe(PairingStatus::Pending)
        ->and($pairing->whatsapp_agent_id)->toBe($agent->id);
});
