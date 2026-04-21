<?php

use App\Models\User;
use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Notifications\BillingPaymentApprovedNotification;
use App\Modules\Billing\Services\BillingApprovalService;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;


beforeEach(function () {
    $this->service = new BillingApprovalService(new SubscriptionService());
});

test('approve sets invoice to paid and records approver', function () {
    Notification::fake();

    $tenant  = Tenant::factory()->create();
    $plan    = SubscriptionPlan::factory()->create();
    $sub     = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'ends_at'   => now()->addDays(3),
    ]);
    $invoice = BillingInvoice::factory()->create([
        'tenant_id'       => $tenant->id,
        'subscription_id' => $sub->id,
        'status'          => BillingInvoiceStatus::Unpaid,
    ]);
    $approver = User::factory()->create(['is_super_admin' => true]);

    $this->service->approve($invoice, $approver);

    $invoice->refresh();

    expect($invoice->status)->toBe(BillingInvoiceStatus::Paid)
        ->and($invoice->approved_by)->toBe($approver->id)
        ->and($invoice->approved_at)->not->toBeNull();
});

test('approve triggers subscription renewal', function () {
    Notification::fake();

    $tenant  = Tenant::factory()->create();
    $plan    = SubscriptionPlan::factory()->create();
    $sub     = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'ends_at'   => now()->addDays(3),
    ]);
    $oldEndsAt = $sub->ends_at->copy();

    $invoice = BillingInvoice::factory()->create([
        'tenant_id'       => $tenant->id,
        'subscription_id' => $sub->id,
        'status'          => BillingInvoiceStatus::Unpaid,
    ]);
    $approver = User::factory()->create(['is_super_admin' => true]);

    $this->service->approve($invoice, $approver);

    $sub->refresh();

    expect($sub->ends_at->isAfter($oldEndsAt))->toBeTrue()
        ->and($sub->status)->toBe(SubscriptionStatus::Active);
});

test('approve dispatches BillingPaymentApprovedNotification to vendor admin', function () {
    Notification::fake();

    $tenant   = Tenant::factory()->create();
    $plan     = SubscriptionPlan::factory()->create();
    $sub      = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'ends_at'   => now()->addDays(3),
    ]);
    $invoice  = BillingInvoice::factory()->create([
        'tenant_id'       => $tenant->id,
        'subscription_id' => $sub->id,
        'status'          => BillingInvoiceStatus::Unpaid,
    ]);
    $vendorAdmin = User::factory()->create();
    $tenant->tenantUsers()->create(['user_id' => $vendorAdmin->id, 'role' => 'vendor_admin']);

    $approver = User::factory()->create(['is_super_admin' => true]);

    $this->service->approve($invoice, $approver);

    Notification::assertSentTo($vendorAdmin, BillingPaymentApprovedNotification::class);
});

test('approve throws validation error for already paid invoice', function () {
    $invoice  = BillingInvoice::factory()->paid()->create();
    $approver = User::factory()->create();

    expect(fn () => $this->service->approve($invoice, $approver))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
