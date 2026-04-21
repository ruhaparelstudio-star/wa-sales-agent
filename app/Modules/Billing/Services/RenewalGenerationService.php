<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Notifications\RenewalInvoiceNotification;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;

class RenewalGenerationService
{
    public function __construct(
        private readonly BillingInvoiceService $billingInvoiceService,
    ) {}

    public function generateUpcomingRenewals(): void
    {
        $cutoff = now()->addDays(7);

        Subscription::whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::GracePeriod->value,
        ])
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', $cutoff)
            ->with(['plan', 'tenant.tenantUsers.user'])
            ->each(function (Subscription $sub) {
                $periodStart = $sub->ends_at->toDateString();

                $alreadyHasUnpaid = BillingInvoice::query()
                    ->where('tenant_id', $sub->tenant_id)
                    ->where('subscription_id', $sub->id)
                    ->where('status', BillingInvoiceStatus::Unpaid->value)
                    ->whereDate('period_start', $periodStart)
                    ->exists();

                if ($alreadyHasUnpaid) {
                    return;
                }

                $invoice = $this->billingInvoiceService->generateForRenewal($sub);

                $adminUsers = $sub->tenant->tenantUsers()
                    ->where('role', 'vendor_admin')
                    ->with('user')
                    ->get()
                    ->pluck('user')
                    ->filter();

                foreach ($adminUsers as $user) {
                    $user->notify(new RenewalInvoiceNotification($invoice));
                }
            });
    }
}
