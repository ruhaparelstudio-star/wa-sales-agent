<?php

use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


beforeEach(function () {
    $this->service = new SubscriptionService();
});

test('assign plan creates pending_payment subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();

    $sub = $this->service->assignPlan($tenant, $plan);

    expect($sub->tenant_id)->toBe($tenant->id)
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($sub->trial_ends_at)->toBeNull();
});

test('assign plan with trial days creates active subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();

    $sub = $this->service->assignPlan($tenant, $plan, trialDays: 14);

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->trial_ends_at)->not->toBeNull()
        ->and($sub->isTrialing())->toBeTrue();
});

test('refresh status sets active when within subscription period', function () {
    $sub = Subscription::factory()->create([
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
        'status'    => SubscriptionStatus::PendingPayment,
    ]);

    $refreshed = $this->service->refreshStatus($sub);

    expect($refreshed->status)->toBe(SubscriptionStatus::Active);
});

test('refresh status sets grace_period when in grace window', function () {
    $sub = Subscription::factory()->create([
        'starts_at'     => now()->subMonths(2),
        'ends_at'       => now()->subDay(),
        'grace_ends_at' => now()->addDays(3),
        'status'        => SubscriptionStatus::Active,
    ]);

    $refreshed = $this->service->refreshStatus($sub);

    expect($refreshed->status)->toBe(SubscriptionStatus::GracePeriod);
});

test('refresh status sets expired when past grace period', function () {
    $sub = Subscription::factory()->create([
        'starts_at'     => now()->subMonths(2),
        'ends_at'       => now()->subDays(10),
        'grace_ends_at' => now()->subDays(3),
        'status'        => SubscriptionStatus::GracePeriod,
    ]);

    $refreshed = $this->service->refreshStatus($sub);

    expect($refreshed->status)->toBe(SubscriptionStatus::Expired);
});

test('refresh status sets expired when past ends_at with no grace period', function () {
    $sub = Subscription::factory()->create([
        'starts_at'     => now()->subMonths(2),
        'ends_at'       => now()->subDays(5),
        'grace_ends_at' => null,
        'status'        => SubscriptionStatus::Active,
    ]);

    $refreshed = $this->service->refreshStatus($sub);

    expect($refreshed->status)->toBe(SubscriptionStatus::Expired);
});

test('refresh status does not change suspended subscription', function () {
    $sub = Subscription::factory()->create([
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
        'status'    => SubscriptionStatus::Suspended,
    ]);

    $refreshed = $this->service->refreshStatus($sub);

    expect($refreshed->status)->toBe(SubscriptionStatus::Suspended);
});

test('daysUntilExpiry returns zero when already expired', function () {
    $sub = Subscription::factory()->create([
        'ends_at' => now()->subSecond(),
        'status'  => SubscriptionStatus::Expired,
    ]);

    expect($sub->daysUntilExpiry())->toBe(0);
});

test('daysUntilExpiry returns zero for subscription expiring exactly now', function () {
    $sub = Subscription::factory()->create([
        'ends_at' => now(),
        'status'  => SubscriptionStatus::Active,
    ]);

    expect($sub->daysUntilExpiry())->toBe(0);
});

test('daysUntilExpiry returns correct days for future expiry', function () {
    $sub = Subscription::factory()->create([
        'ends_at' => now()->addDays(10),
        'status'  => SubscriptionStatus::Active,
    ]);

    expect($sub->daysUntilExpiry())->toBe(10);
});

test('renewSubscription advances ends_at by one month and sets active', function () {
    $sub = Subscription::factory()->create([
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays(2),
        'status'    => SubscriptionStatus::Active,
    ]);

    $oldEndsAt = $sub->ends_at->copy();
    $renewed   = $this->service->renewSubscription($sub);

    expect($renewed->status)->toBe(SubscriptionStatus::Active)
        ->and($renewed->grace_ends_at)->toBeNull()
        ->and($renewed->ends_at->diffInDays($oldEndsAt, true))->toBeGreaterThan(25);
});
