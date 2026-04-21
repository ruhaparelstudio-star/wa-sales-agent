<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

class TenantMembershipService
{
    public function addUserToTenant(Tenant $tenant, User $user, TenantUserRole $role): TenantUser
    {
        return TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => $role->value],
        );
    }

    public function getUserRole(Tenant $tenant, User $user): ?TenantUserRole
    {
        $tenantUser = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        return $tenantUser?->role;
    }

    public function getAdminsForTenant(Tenant $tenant): Collection
    {
        return User::whereHas('tenantUsers', function ($query) use ($tenant) {
            $query->where('tenant_id', $tenant->id)
                ->where('role', TenantUserRole::VendorAdmin->value);
        })->get();
    }

    public function removeUserFromTenant(Tenant $tenant, User $user): void
    {
        TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
