<?php

namespace App\Modules\Billing\Enums;

enum BillingInvoiceStatus: string
{
    case Unpaid    = 'unpaid';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';
}
