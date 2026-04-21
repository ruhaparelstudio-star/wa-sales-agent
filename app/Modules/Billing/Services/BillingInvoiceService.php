<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class BillingInvoiceService
{
    public function generateForRenewal(Subscription $sub): BillingInvoice
    {
        $periodStart = $sub->ends_at->toDateString();
        $periodEnd   = $sub->ends_at->copy()->addMonth()->toDateString();

        return BillingInvoice::create([
            'tenant_id'       => $sub->tenant_id,
            'subscription_id' => $sub->id,
            'invoice_number'  => $this->generateInvoiceNumber(),
            'amount'          => $sub->plan->price,
            'status'          => BillingInvoiceStatus::Unpaid,
            'due_date'        => $sub->ends_at->copy()->subDays(3)->toDateString(),
            'period_start'    => $periodStart,
            'period_end'      => $periodEnd,
        ]);
    }

    public function getUnpaidInvoices(Tenant $tenant): Collection
    {
        return BillingInvoice::forTenant($tenant->id)->unpaid()->latest()->get();
    }

    public function uploadProof(BillingInvoice $invoice, UploadedFile $file): BillingInvoice
    {
        $path = $file->store("billing-proofs/{$invoice->tenant_id}", 'local');

        $invoice->proof_path        = $path;
        $invoice->proof_uploaded_at = now();
        $invoice->save();

        return $invoice;
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $count  = BillingInvoice::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return $prefix . str_pad($count, 6, '0', STR_PAD_LEFT);
    }
}
