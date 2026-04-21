<?php

namespace App\Modules\Billing\DTOs;

use Illuminate\Http\UploadedFile;

class UploadBillingProofDTO
{
    public function __construct(
        public readonly int $billingInvoiceId,
        public readonly UploadedFile $file,
    ) {}
}
