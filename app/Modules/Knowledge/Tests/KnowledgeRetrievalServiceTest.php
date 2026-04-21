<?php

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Knowledge\Services\KnowledgeService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;


beforeEach(fn () => Cache::flush());

function makeRetrievalService(): KnowledgeRetrievalService
{
    return new KnowledgeRetrievalService();
}

test('tidak return data tenant lain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    KnowledgeItem::factory()->count(3)->forTenant($tenantA)->faq()->create();
    KnowledgeItem::factory()->count(2)->forTenant($tenantB)->faq()->create();

    $result = makeRetrievalService()->getCachedHeaders($tenantA);

    expect($result)->toHaveCount(3)
        ->and($result->pluck('tenant_id')->unique()->first())->toBe($tenantA->id);
});

test('getRelevantSubset max 3 items, tipe package untuk intent tanya_harga', function () {
    $tenant = Tenant::factory()->create();

    KnowledgeItem::factory()->count(5)->forTenant($tenant)->package()->create();
    KnowledgeItem::factory()->count(3)->forTenant($tenant)->faq()->create();

    $result = makeRetrievalService()->getRelevantSubset($tenant, 'tanya_harga');

    expect($result)->toHaveCount(3)
        ->and($result->every(fn ($item) => $item->type === KnowledgeType::Package))->toBeTrue();
});

test('getRelevantSubset tipe objection untuk intent keberatan', function () {
    $tenant = Tenant::factory()->create();

    KnowledgeItem::factory()->count(3)->forTenant($tenant)->objection()->create();
    KnowledgeItem::factory()->count(3)->forTenant($tenant)->faq()->create();

    $result = makeRetrievalService()->getRelevantSubset($tenant, 'keberatan');

    expect($result)->toHaveCount(3)
        ->and($result->every(fn ($item) => $item->type === KnowledgeType::Objection))->toBeTrue();
});

test('getRelevantSubset fallback ke tipe lain jika kurang dari limit', function () {
    $tenant = Tenant::factory()->create();

    KnowledgeItem::factory()->forTenant($tenant)->package()->create();
    KnowledgeItem::factory()->count(3)->forTenant($tenant)->faq()->create();

    $result = makeRetrievalService()->getRelevantSubset($tenant, 'tanya_harga');

    expect($result)->toHaveCount(3);
    expect($result->filter(fn ($item) => $item->type === KnowledgeType::Package))->toHaveCount(1);
});

test('getCachedHeaders tidak return item inactive', function () {
    $tenant = Tenant::factory()->create();

    KnowledgeItem::factory()->count(3)->forTenant($tenant)->create();
    KnowledgeItem::factory()->forTenant($tenant)->inactive()->create();

    $result = makeRetrievalService()->getCachedHeaders($tenant);

    expect($result)->toHaveCount(3)
        ->and($result->every(fn ($item) => ! isset($item->is_active) || $item->is_active))->toBeTrue();
});

test('cache invalid setelah toggle knowledge item', function () {
    $tenant  = Tenant::factory()->create();
    $item    = KnowledgeItem::factory()->forTenant($tenant)->create();
    $service = new KnowledgeService();

    $before = makeRetrievalService()->getCachedHeaders($tenant);
    expect($before)->toHaveCount(1);

    $service->toggle($item);

    $after = makeRetrievalService()->getCachedHeaders($tenant);
    expect($after)->toHaveCount(0);
});
