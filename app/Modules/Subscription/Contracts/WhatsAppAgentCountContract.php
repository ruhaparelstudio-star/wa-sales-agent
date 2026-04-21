<?php

namespace App\Modules\Subscription\Contracts;

interface WhatsAppAgentCountContract
{
    public function getConnectedCount(int $tenantId): int;
}
