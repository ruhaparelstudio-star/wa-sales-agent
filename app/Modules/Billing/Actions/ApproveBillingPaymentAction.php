<?php

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\DTOs\ApproveBillingPaymentDTO;
use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Services\BillingApprovalService;
use Illuminate\Validation\ValidationException;

class ApproveBillingPaymentAction
{
    public function __construct(
        private readonly BillingApprovalService $billingApprovalService,
    ) {}

    public function execute(ApproveBillingPaymentDTO $dto, User $approver): void
    {
        $invoice = BillingInvoice::findOrFail($dto->billingInvoiceId);

        if ($invoice->status !== BillingInvoiceStatus::Unpaid) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice is not in unpaid status.',
            ]);
        }

        $this->billingApprovalService->approve($invoice, $approver);
    }
}
