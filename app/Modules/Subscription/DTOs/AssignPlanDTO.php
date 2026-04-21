<?php

namespace App\Modules\Subscription\DTOs;

class AssignPlanDTO
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $planId,
        public readonly int $trialDays = 0,
    ) {}
}
