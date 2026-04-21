<?php

namespace App\Modules\WhatsApp\Enums;

enum PairingStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
