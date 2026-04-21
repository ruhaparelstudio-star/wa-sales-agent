<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\ServiceCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SuperAdminServiceCatalogController extends Controller
{
    public function index(): View
    {
        $services = ServiceCatalog::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('superadmin.services.index', compact('services'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 2;

        while (ServiceCatalog::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        ServiceCatalog::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()
            ->route('superadmin.services.index')
            ->with('success', 'Master layanan berhasil ditambahkan.');
    }

    public function update(Request $request, ServiceCatalog $serviceCatalog): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $serviceCatalog->update([
            'name' => $validated['name'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()
            ->route('superadmin.services.index')
            ->with('success', 'Master layanan berhasil diperbarui.');
    }
}
