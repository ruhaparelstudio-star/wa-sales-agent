<?php

namespace App\Modules\WhatsApp\Enums;

enum OutboundDispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
