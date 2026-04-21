<?php

namespace App\Modules\Invoice\Actions;

use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\ClientInvoiceDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

class SendClientInvoiceAction
{
    public function __construct(
        private readonly ClientInvoiceDispatchService $dispatchService,
    ) {}

    public function execute(int $invoiceId, Tenant $tenant): void
    {
        $invoice = ClientInvoice::forTenant($tenant->id)->find($invoiceId);

        if (! $invoice) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Invoice not found or does not belong to your tenant.',
            ]);
        }

        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Only draft invoices can be sent.',
            ]);
        }

        $this->dispatchService->dispatch($invoice);
    }
}
