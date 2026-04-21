<?php

namespace App\Modules\Tenancy\DTOs;

class CreateTenantDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly int $planId,
        public readonly int $trialDays = 14,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            slug: $data['slug'],
            adminName: $data['admin_name'],
            adminEmail: $data['admin_email'],
            planId: (int) $data['plan_id'],
            trialDays: $data['trial_days'] ?? 14,
        );
    }
}
