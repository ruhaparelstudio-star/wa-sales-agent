<?php

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Notifications\RenewalInvoiceNotification;
use App\Modules\Billing\Services\BillingInvoiceService;
use App\Modules\Billing\Services\RenewalGenerationService;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;


beforeEach(function () {
    $this->service = new RenewalGenerationService(new BillingInvoiceService());
    Notification::fake();
});

test('generates invoice for subscription expiring within 7 days', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['price' => 299000]);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays(5),
    ]);

    $this->service->generateUpcomingRenewals();

    expect(BillingInvoice::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('does not generate duplicate invoice if unpaid already exists for period', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create(['price' => 299000]);
    $sub    = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays(5),
    ]);

    // Pre-existing unpaid invoice for the same period
    BillingInvoice::factory()->create([
        'tenant_id'       => $tenant->id,
        'subscription_id' => $sub->id,
        'status'          => BillingInvoiceStatus::Unpaid,
        'period_start'    => $sub->ends_at->toDateString(),
    ]);

    $this->service->generateUpcomingRenewals();

    expect(BillingInvoice::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('does not generate invoice for subscription expiring beyond 7 days', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays(10),
    ]);

    $this->service->generateUpcomingRenewals();

    expect(BillingInvoice::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('notifies vendor admin when renewal invoice is generated', function () {
    $tenant      = Tenant::factory()->create();
    $plan        = SubscriptionPlan::factory()->create(['price' => 299000]);
    $vendorAdmin = \App\Models\User::factory()->create();
    $tenant->tenantUsers()->create(['user_id' => $vendorAdmin->id, 'role' => 'vendor_admin']);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at'   => now()->addDays(5),
    ]);

    $this->service->generateUpcomingRenewals();

    Notification::assertSentTo($vendorAdmin, RenewalInvoiceNotification::class);
});

test('does not generate invoice for expired subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan   = SubscriptionPlan::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Expired,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => now()->subDays(5),
    ]);

    $this->service->generateUpcomingRenewals();

    expect(BillingInvoice::count())->toBe(0);
});
