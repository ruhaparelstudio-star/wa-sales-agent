<?php

namespace App\Modules\Conversations\Enums;

enum HandoffStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
