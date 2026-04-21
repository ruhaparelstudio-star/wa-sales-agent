<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PricelistService
{
    public function listFiles(Tenant $tenant): Collection
    {
        return collect(Storage::files($this->directory($tenant)))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.pdf'))
            ->map(fn (string $path) => [
                'path' => $path,
                'filename' => basename($path),
                'size' => Storage::size($path),
                'last_modified' => Storage::lastModified($path),
            ])
            ->sortByDesc('last_modified')
            ->values();
    }

    public function findLatestPdf(Tenant $tenant): ?string
    {
        $files = $this->listFiles($tenant);

        return $files->first()['path'] ?? null;
    }

    public function filename(?string $path): ?string
    {
        return $path ? basename($path) : null;
    }

    public function absolutePath(string $path): string
    {
        return Storage::path($path);
    }

    public function store(Tenant $tenant, UploadedFile $file): string
    {
        $filename = now()->format('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());

        return $file->storeAs($this->directory($tenant), $filename, 'local');
    }

    public function delete(Tenant $tenant, string $filename): void
    {
        $path = $this->pathForFilename($tenant, $filename);

        if ($path !== null) {
            Storage::delete($path);
        }
    }

    public function pathForFilename(Tenant $tenant, string $filename): ?string
    {
        $cleanFilename = basename($filename);
        $path = $this->directory($tenant) . '/' . $cleanFilename;

        return Storage::exists($path) ? $path : null;
    }

    public function directory(Tenant $tenant): string
    {
        return "tenants/{$tenant->id}/pricelists";
    }
}
