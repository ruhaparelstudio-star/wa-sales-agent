<?php

namespace App\Modules\WhatsApp\Exceptions;

use RuntimeException;

class AgentSlotFullException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No agent slots available for this tenant. Upgrade your plan to add more agents.');
    }
}
