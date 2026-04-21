<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\ServiceCatalog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class TenantProfileController extends Controller
{
    public function edit(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->get()->load('primaryServiceCatalog');
        $services = ServiceCatalog::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.profile.edit', compact('tenant', 'services'));
    }

    public function update(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->get();
        $tenantId = $tenant->id;
        $user = $request->user();

        abort_unless($user?->tenantRole($tenantId) === TenantUserRole::VendorAdmin, 403);

        $validated = $request->validate([
            'primary_service_catalog_id' => ['nullable', 'integer', 'exists:service_catalogs,id'],
        ]);

        $service = null;
        if (($validated['primary_service_catalog_id'] ?? null) !== null) {
            $service = ServiceCatalog::query()
                ->active()
                ->findOrFail((int) $validated['primary_service_catalog_id']);
        }

        $tenant->forceFill([
            'primary_service_catalog_id' => $service?->id,
        ])->save();

        return redirect()
            ->route('tenant-profile.edit')
            ->with('success', 'Profile tenant berhasil diperbarui.');
    }
}
