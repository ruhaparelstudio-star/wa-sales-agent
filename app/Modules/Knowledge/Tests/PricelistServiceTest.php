<?php

use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makePricelistService(): PricelistService
{
    return new PricelistService();
}

test('store uploads pricelist pdf into tenant directory', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $file = UploadedFile::fake()->create('Wedding Pricelist 2026.pdf', 1200, 'application/pdf');

    $path = makePricelistService()->store($tenant, $file);

    expect($path)->toContain("tenants/{$tenant->id}/pricelists/")
        ->and(strtolower($path))->toEndWith('.pdf');

    Storage::disk('local')->assertExists($path);
});

test('listFiles returns uploaded pricelist metadata', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $file = UploadedFile::fake()->create('price-list.pdf', 512, 'application/pdf');
    makePricelistService()->store($tenant, $file);

    $files = makePricelistService()->listFiles($tenant);

    expect($files)->toHaveCount(1)
        ->and($files->first()['filename'])->toContain('price-list.pdf');
});
