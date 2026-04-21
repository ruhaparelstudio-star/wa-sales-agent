<?php

namespace App\Modules\Billing\DTOs;

class ApproveBillingPaymentDTO
{
    public function __construct(
        public readonly int $billingInvoiceId,
    ) {}
}
