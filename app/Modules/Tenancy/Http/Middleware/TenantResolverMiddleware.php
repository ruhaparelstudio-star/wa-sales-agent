<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantGuardService;
use App\Modules\Tenancy\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantResolverMiddleware
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly TenantContext $tenantContext,
        private readonly TenantGuardService $tenantGuardService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            if ($user->isSuperAdmin() && ! $request->expectsJson()) {
                return redirect()->route('superadmin.tenants.index');
            }

            abort(403, 'No active tenant found for this user.');
        }

        $this->tenantGuardService->assertUserBelongsToTenant($user, $tenant);

        $this->tenantContext->set($tenant);

        return $next($request);
    }
}
