<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use RuntimeException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): Tenant
    {
        if ($this->tenant === null) {
            throw new RuntimeException('Tenant context has not been set for this request.');
        }

        return $this->tenant;
    }

    public function getTenantId(): int
    {
        return $this->get()->id;
    }

    public function isSet(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
