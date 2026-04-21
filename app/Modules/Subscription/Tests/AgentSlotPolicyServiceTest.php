<?php

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeSlotService(int $connectedCount): AgentSlotPolicyService
{
    $mockCounter = Mockery::mock(WhatsAppAgentCountContract::class);
    $mockCounter->shouldReceive('getConnectedCount')->andReturn($connectedCount);

    return new AgentSlotPolicyService($mockCounter, new SubscriptionService());
}

test('connected agents count as used slots', function () {
    $tenant = Tenant::factory()->create();
    SubscriptionPlan::factory()->create(['max_agents' => 3]);

    $service = makeSlotService(connectedCount: 2);

    expect($service->getUsedSlots($tenant))->toBe(2);
});

test('disconnected agents do not count as used slots', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['max_agents' => 3]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    // Only 1 connected; disconnected ones are excluded by the contract
    $service = makeSlotService(connectedCount: 1);

    expect($service->getUsedSlots($tenant))->toBe(1)
        ->and($service->getRemainingSlots($tenant))->toBe(2)
        ->and($service->isSlotAvailable($tenant))->toBeTrue();
});

test('no remaining slots when at max capacity', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['max_agents' => 2]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $service = makeSlotService(connectedCount: 2);

    expect($service->getRemainingSlots($tenant))->toBe(0)
        ->and($service->isSlotAvailable($tenant))->toBeFalse();
});

test('no slots available when tenant has no active subscription', function () {
    $tenant  = Tenant::factory()->create();
    $service = makeSlotService(connectedCount: 0);

    expect($service->getRemainingSlots($tenant))->toBe(0)
        ->and($service->isSlotAvailable($tenant))->toBeFalse();
});
