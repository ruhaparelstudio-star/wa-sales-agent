<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Notifications\BillingPaymentApprovedNotification;
use App\Modules\Subscription\Services\SubscriptionService;
use Illuminate\Validation\ValidationException;

class BillingApprovalService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function approve(BillingInvoice $invoice, User $approver): void
    {
        if ($invoice->status !== BillingInvoiceStatus::Unpaid) {
            throw ValidationException::withMessages([
                'invoice' => 'Only unpaid invoices can be approved.',
            ]);
        }

        $invoice->status      = BillingInvoiceStatus::Paid;
        $invoice->approved_by = $approver->id;
        $invoice->approved_at = now();
        $invoice->save();

        if ($invoice->subscription) {
            $this->subscriptionService->renewSubscription($invoice->subscription);
        }

        $adminUsers = $invoice->tenant->tenantUsers()
            ->where('role', 'vendor_admin')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($adminUsers as $user) {
            $user->notify(new BillingPaymentApprovedNotification($invoice));
        }
    }

    public function reject(BillingInvoice $invoice, User $approver, string $reason): void
    {
        if ($invoice->status !== BillingInvoiceStatus::Unpaid) {
            throw ValidationException::withMessages([
                'invoice' => 'Only unpaid invoices can be rejected.',
            ]);
        }

        $invoice->status = BillingInvoiceStatus::Cancelled;
        $invoice->notes  = $reason;
        $invoice->save();
    }
}
