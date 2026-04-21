<?php

use App\Modules\Auth\Models\TenantUser;
use App\Modules\Billing\Jobs\SendBillingAlertsJob;
use App\Modules\Billing\Notifications\BillingAlertNotification;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;


beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

function makeActiveSubscription(int $daysFromNow): array
{
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();
    $admin  = User::factory()->create();
    $tenant->tenantUsers()->create(['user_id' => $admin->id, 'role' => 'vendor_admin']);

    $sub = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays($daysFromNow)->startOfDay(),
    ]);

    return [$tenant, $admin, $sub];
}

test('sends H-7 alert to vendor admin', function () {
    [, $admin] = makeActiveSubscription(7);

    (new SendBillingAlertsJob())->handle(
        app(\App\Modules\Auth\Services\TenantMembershipService::class)
    );

    Notification::assertSentTo($admin, BillingAlertNotification::class);
});

test('sends H-3 alert to vendor admin', function () {
    [, $admin] = makeActiveSubscription(3);

    (new SendBillingAlertsJob())->handle(
        app(\App\Modules\Auth\Services\TenantMembershipService::class)
    );

    Notification::assertSentTo($admin, BillingAlertNotification::class);
});

test('sends H-1 alert to vendor admin', function () {
    [, $admin] = makeActiveSubscription(1);

    (new SendBillingAlertsJob())->handle(
        app(\App\Modules\Auth\Services\TenantMembershipService::class)
    );

    Notification::assertSentTo($admin, BillingAlertNotification::class);
});

test('idempotency: does not send same alert twice within 23 hours', function () {
    [, $admin, $sub] = makeActiveSubscription(7);

    $membership = app(\App\Modules\Auth\Services\TenantMembershipService::class);

    (new SendBillingAlertsJob())->handle($membership);
    (new SendBillingAlertsJob())->handle($membership);

    Notification::assertSentToTimes($admin, BillingAlertNotification::class, 1);
});

test('does not send alert to vendor admin of a different tenant', function () {
    makeActiveSubscription(7);

    $otherTenant = Tenant::factory()->create();
    $otherAdmin  = User::factory()->create();
    $otherTenant->tenantUsers()->create(['user_id' => $otherAdmin->id, 'role' => 'vendor_admin']);

    (new SendBillingAlertsJob())->handle(
        app(\App\Modules\Auth\Services\TenantMembershipService::class)
    );

    Notification::assertNotSentTo($otherAdmin, BillingAlertNotification::class);
});

test('BillingAlertNotification message matches days remaining', function () {
    $plan = SubscriptionPlan::factory()->create();
    $sub  = Subscription::factory()->create([
        'plan_id'  => $plan->id,
        'ends_at'  => now()->addDays(3),
    ]);

    $notification = new BillingAlertNotification($sub, 3);
    $data = $notification->toDatabase(new User());

    expect($data['days_remaining'])->toBe(3)
        ->and($data['message'])->toContain('3 hari');
});

test('BillingAlertNotification expired message for negative days', function () {
    $plan = SubscriptionPlan::factory()->create();
    $sub  = Subscription::factory()->create([
        'plan_id'  => $plan->id,
        'ends_at'  => now()->subDay(),
    ]);

    $notification = new BillingAlertNotification($sub, -1);
    $data = $notification->toDatabase(new User());

    expect($data['message'])->toContain('expired');
});
