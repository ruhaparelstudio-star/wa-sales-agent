<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PricelistController
{
    public function store(Request $request, TenantContext $tenantContext, PricelistService $service): RedirectResponse
    {
        $request->validate([
            'pricelist_file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $service->store($tenantContext->get(), $request->file('pricelist_file'));

        return redirect()
            ->route('pricelists.index')
            ->with('success', 'Pricelist PDF uploaded.');
    }

    public function download(string $filename, TenantContext $tenantContext, PricelistService $service): BinaryFileResponse|StreamedResponse
    {
        $tenant = $tenantContext->get();
        $path = $service->pathForFilename($tenant, $filename);

        abort_if($path === null, 404);

        return response()->download($service->absolutePath($path), basename($path));
    }

    public function destroy(string $filename, TenantContext $tenantContext, PricelistService $service): RedirectResponse
    {
        $service->delete($tenantContext->get(), $filename);

        return redirect()
            ->route('pricelists.index')
            ->with('success', 'Pricelist PDF deleted.');
    }
}
