<?php

namespace App\Modules\Conversations\Enums;

enum ConversationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Handoff = 'handoff';
}
