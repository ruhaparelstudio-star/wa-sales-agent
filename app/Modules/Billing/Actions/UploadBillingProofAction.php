<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\DTOs\UploadBillingProofDTO;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Notifications\BillingPaymentReceivedNotification;
use App\Modules\Billing\Services\BillingInvoiceService;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UploadBillingProofAction
{
    public function __construct(
        private readonly BillingInvoiceService $billingInvoiceService,
    ) {}

    public function execute(UploadBillingProofDTO $dto, int $tenantId): BillingInvoice
    {
        $invoice = BillingInvoice::findOrFail($dto->billingInvoiceId);

        if ($invoice->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice does not belong to your tenant.',
            ]);
        }

        $invoice = $this->billingInvoiceService->uploadProof($invoice, $dto->file);

        // Notify all super admins
        User::where('is_super_admin', true)->each(function (User $superAdmin) use ($invoice) {
            $superAdmin->notify(new BillingPaymentReceivedNotification($invoice));
        });

        return $invoice;
    }
}
