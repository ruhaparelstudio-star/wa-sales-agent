<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Http\Livewire\QrPairingModal;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use App\Modules\WhatsApp\Services\PairingService;
use Livewire\Livewire;

it('initiates pairing on mount', function () {
    $tenant  = Tenant::factory()->create();
    $user    = \App\Models\User::factory()->create();
    app(TenantContext::class)->set($tenant);

    $agent = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);
    $pairing = WhatsAppPairing::factory()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
        'status' => PairingStatus::Pending,
    ]);

    $mockService = Mockery::mock(PairingService::class);
    $mockService->shouldReceive('initiatePairing')->once()->andReturn($pairing);
    app()->instance(PairingService::class, $mockService);

    $mockProvider = Mockery::mock(WhatsAppProviderInterface::class);
    $mockProvider->shouldReceive('getQrStreamUrl')
        ->once()
        ->with($agent->id)
        ->andReturn("http://localhost:8080/baileys/agents/{$agent->id}/qr-stream");
    app()->instance(WhatsAppProviderInterface::class, $mockProvider);

    Livewire::actingAs($user)
        ->test(QrPairingModal::class)
        ->assertSet('pairingId', $pairing->id)
        ->assertSet('sseUrl', "http://localhost:8080/baileys/agents/{$agent->id}/qr-stream")
        ->assertSet('status', 'waiting');
});

it('cancel pairing updates status to cancelled', function () {
    $tenant  = Tenant::factory()->create();
    $user    = \App\Models\User::factory()->create();
    app(TenantContext::class)->set($tenant);

    $agent = WhatsAppAgent::factory()->pending()->create(['tenant_id' => $tenant->id]);
    $pairing = WhatsAppPairing::factory()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
        'status'    => PairingStatus::Pending,
    ]);

    $mockService = Mockery::mock(PairingService::class);
    $mockService->shouldReceive('initiatePairing')->once()->andReturn($pairing);
    $mockService->shouldReceive('cancelPairing')->once()->with(Mockery::type(WhatsAppPairing::class));
    app()->instance(PairingService::class, $mockService);

    $mockProvider = Mockery::mock(WhatsAppProviderInterface::class);
    $mockProvider->shouldReceive('getQrStreamUrl')
        ->once()
        ->with($agent->id)
        ->andReturn("http://localhost:8080/baileys/agents/{$agent->id}/qr-stream");
    app()->instance(WhatsAppProviderInterface::class, $mockProvider);

    Livewire::actingAs($user)
        ->test(QrPairingModal::class)
        ->set('pairingId', $pairing->id)
        ->call('closeModal');
});
