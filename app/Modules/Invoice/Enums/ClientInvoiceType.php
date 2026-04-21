<?php

namespace App\Modules\Invoice\Enums;

enum ClientInvoiceType: string
{
    case Created  = 'created';
    case Uploaded = 'uploaded';
}
