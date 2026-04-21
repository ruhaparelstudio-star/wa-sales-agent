<?php

namespace App\Modules\WhatsApp\Enums;

enum AgentStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
}
