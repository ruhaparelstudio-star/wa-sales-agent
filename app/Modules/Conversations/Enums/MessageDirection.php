<?php

namespace App\Modules\Conversations\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
