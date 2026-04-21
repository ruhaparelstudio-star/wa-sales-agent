<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;

class TenantGuardService
{
    public function assertUserBelongsToTenant(User $user, Tenant $tenant): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        if (! $user->belongsToTenant($tenant->id)) {
            throw new AuthorizationException('User does not have access to this tenant.');
        }
    }
}
