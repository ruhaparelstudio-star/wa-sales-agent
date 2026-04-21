<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Auth\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

class TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            // Super admin can operate in any tenant via X-Tenant-Id header
            $tenantId = $request->header('X-Tenant-Id');
            if ($tenantId) {
                return Tenant::active()->find($tenantId);
            }

            return null;
        }

        // Resolve from session first (supports switching between tenants)
        $sessionTenantId = $request->session()->get('tenant_id');
        if ($sessionTenantId) {
            $tenantUser = TenantUser::where('user_id', $user->id)
                ->where('tenant_id', $sessionTenantId)
                ->first();

            if ($tenantUser) {
                return Tenant::active()->find($sessionTenantId);
            }
        }

        // Fall back to first tenant the user belongs to
        $tenantUser = TenantUser::where('user_id', $user->id)->first();

        return $tenantUser
            ? Tenant::active()->find($tenantUser->tenant_id)
            : null;
    }
}
