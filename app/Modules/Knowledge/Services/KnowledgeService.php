<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KnowledgeService
{
    public function getAll(Tenant $tenant, ?KnowledgeType $type = null): Collection
    {
        $query = KnowledgeItem::forTenant($tenant->id)->orderBy('sort_order');

        if ($type !== null) {
            $query->ofType($type);
        }

        return $query->get();
    }

    public function create(Tenant $tenant, array $data): KnowledgeItem
    {
        $item = KnowledgeItem::create([
            'tenant_id'  => $tenant->id,
            'type'       => $data['type'],
            'title'      => $data['title'],
            'content'    => $data['content'],
            'tags'       => $data['tags'] ?? null,
            'is_active'  => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->invalidateCache($tenant->id);

        return $item;
    }

    public function update(KnowledgeItem $item, array $data): KnowledgeItem
    {
        $item->update($data);
        $this->invalidateCache($item->tenant_id);

        return $item->fresh();
    }

    public function toggle(KnowledgeItem $item): void
    {
        $item->update(['is_active' => ! $item->is_active]);
        $this->invalidateCache($item->tenant_id);
    }

    private function invalidateCache(int $tenantId): void
    {
        Cache::forever($this->versionCacheKey($tenantId), $this->cache()->get($this->versionCacheKey($tenantId), 1) + 1);
    }

    private function cache(): Repository
    {
        return Cache::store();
    }

    private function versionCacheKey(int $tenantId): string
    {
        return "knowledge:tenant:{$tenantId}:version";
    }
}
