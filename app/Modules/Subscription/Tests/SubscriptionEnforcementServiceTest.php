<?php

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Exceptions\SubscriptionException;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionEnforcementService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeEnforcementService(int $connectedCount): SubscriptionEnforcementService
{
    $mockCounter = Mockery::mock(WhatsAppAgentCountContract::class);
    $mockCounter->shouldReceive('getConnectedCount')->andReturn($connectedCount);

    $subscriptionService = new SubscriptionService();
    $slotService         = new AgentSlotPolicyService($mockCounter, $subscriptionService);

    return new SubscriptionEnforcementService($subscriptionService, $slotService);
}

test('expired tenant cannot add agent', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['max_agents' => 3]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Expired,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => now()->subDays(10),
    ]);

    $service = makeEnforcementService(connectedCount: 0);

    expect($service->canAddAgent($tenant))->toBeFalse();
});

test('suspended tenant cannot add agent', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['max_agents' => 3]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Suspended,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $service = makeEnforcementService(connectedCount: 0);

    expect($service->canAddAgent($tenant))->toBeFalse();
});

test('active tenant with available slot can add agent', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['max_agents' => 3]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $service = makeEnforcementService(connectedCount: 1);

    expect($service->canAddAgent($tenant))->toBeTrue();
});

test('assert can send outbound throws for expired subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Expired,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => now()->subDays(10),
    ]);

    $service = makeEnforcementService(connectedCount: 0);

    expect(fn () => $service->assertCanSendOutbound($tenant))
        ->toThrow(SubscriptionException::class);
});

test('assert can send outbound throws when no subscription', function () {
    $tenant  = Tenant::factory()->create();
    $service = makeEnforcementService(connectedCount: 0);

    expect(fn () => $service->assertCanSendOutbound($tenant))
        ->toThrow(SubscriptionException::class);
});

test('assert can send outbound passes for active subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $service = makeEnforcementService(connectedCount: 0);

    expect(fn () => $service->assertCanSendOutbound($tenant))->not->toThrow(SubscriptionException::class);
});
