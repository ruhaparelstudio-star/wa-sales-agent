<?php

namespace App\Modules\Invoice\Enums;

enum ClientInvoiceStatus: string
{
    case Draft     = 'draft';
    case Sent      = 'sent';
    case Delivered = 'delivered';
    case Viewed    = 'viewed';
    case Paid      = 'paid';
    case Overdue   = 'overdue';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Viewed], true);
    }
}
