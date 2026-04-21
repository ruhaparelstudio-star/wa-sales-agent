<?php

namespace App\Modules\WhatsApp\Exceptions;

use RuntimeException;

class AgentAlreadyConnectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This WhatsApp number is already connected. Disconnect it first before re-pairing.');
    }
}
