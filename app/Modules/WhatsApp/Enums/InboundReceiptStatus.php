<?php

namespace App\Modules\WhatsApp\Enums;

enum InboundReceiptStatus: string
{
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
