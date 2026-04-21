<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KnowledgeRetrievalService
{
    public function getRelevantSubset(Tenant $tenant, string $intent, int $limit = 3): Collection
    {
        $cached = $this->cache()->remember(
            $this->subsetCacheKey($tenant, $intent, $limit),
            300,
            function () use ($tenant, $intent, $limit) {
                $preferredType = $this->resolvePreferredType($intent);

                $primary = KnowledgeItem::forTenant($tenant->id)
                    ->active()
                    ->ofType($preferredType)
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get();

                if ($primary->count() >= $limit) {
                    return $this->serializeItems($primary);
                }

                $remaining = $limit - $primary->count();
                $fallback  = KnowledgeItem::forTenant($tenant->id)
                    ->active()
                    ->where('type', '!=', $preferredType->value)
                    ->orderBy('sort_order')
                    ->limit($remaining)
                    ->get();

                return $this->serializeItems($primary->merge($fallback)->take($limit));
            }
        );

        return $this->deserializeItems($cached);
    }

    public function getPackageSubset(
        Tenant $tenant,
        ?string $packageInterest = null,
        ?string $eventType = null,
        int $limit = 3,
    ): Collection {
        $cached = $this->cache()->remember(
            $this->packageSubsetCacheKey($tenant, $packageInterest, $eventType, $limit),
            300,
            function () use ($tenant, $packageInterest, $eventType, $limit) {
                $items = KnowledgeItem::forTenant($tenant->id)
                    ->active()
                    ->ofType(KnowledgeType::Package)
                    ->orderBy('sort_order')
                    ->get();

                if ($items->isEmpty()) {
                    return [];
                }

                $scored = $items->map(function (KnowledgeItem $item) use ($packageInterest, $eventType): array {
                    return [
                        'item' => $item,
                        'score' => $this->packageRelevanceScore($item, $packageInterest, $eventType),
                    ];
                })->sortByDesc('score')->values();

                $preferred = $scored
                    ->filter(static fn (array $row): bool => $row['score'] > 0)
                    ->pluck('item');

                $selected = ($preferred->isNotEmpty() ? $preferred : $items)
                    ->take($limit)
                    ->values();

                return $this->serializeItems($selected);
            }
        );

        return $this->deserializeItems($cached);
    }

    public function getCachedHeaders(Tenant $tenant): Collection
    {
        $cached = $this->cache()->remember(
            $this->headersCacheKey($tenant),
            600,
            fn () => $this->serializeItems(
                KnowledgeItem::forTenant($tenant->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'tenant_id', 'title', 'type'])
            )
        );

        return $this->deserializeItems($cached);
    }

    private function serializeItems(Collection $items): array
    {
        return $items->map(fn (KnowledgeItem $item) => [
            'id' => $item->id,
            'tenant_id' => $item->tenant_id,
            'title' => $item->title,
            'content' => $item->content,
            'type' => $item->type->value,
            'sort_order' => $item->sort_order,
        ])->all();
    }

    private function deserializeItems(mixed $cached): Collection
    {
        return collect(is_array($cached) ? $cached : [])
            ->map(function (array $item) {
                return (object) [
                    'id' => $item['id'] ?? null,
                    'tenant_id' => $item['tenant_id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'content' => $item['content'] ?? null,
                    'type' => isset($item['type']) ? KnowledgeType::from($item['type']) : null,
                    'sort_order' => $item['sort_order'] ?? 0,
                ];
            });
    }

    private function cache(): Repository
    {
        return Cache::store();
    }

    private function headersCacheKey(Tenant $tenant): string
    {
        return sprintf(
            'knowledge:tenant:%d:headers:v%d',
            $tenant->id,
            $this->cacheVersion($tenant),
        );
    }

    private function subsetCacheKey(Tenant $tenant, string $intent, int $limit): string
    {
        return sprintf(
            'knowledge:tenant:%d:subset:%s:%d:v%d',
            $tenant->id,
            md5($intent),
            $limit,
            $this->cacheVersion($tenant),
        );
    }

    private function packageSubsetCacheKey(Tenant $tenant, ?string $packageInterest, ?string $eventType, int $limit): string
    {
        return sprintf(
            'knowledge:tenant:%d:package-subset:%s:%s:%d:v%d',
            $tenant->id,
            md5((string) $packageInterest),
            md5((string) $eventType),
            $limit,
            $this->cacheVersion($tenant),
        );
    }

    private function versionCacheKey(int $tenantId): string
    {
        return "knowledge:tenant:{$tenantId}:version";
    }

    private function cacheVersion(Tenant $tenant): int
    {
        return (int) $this->cache()->get($this->versionCacheKey($tenant->id), 1);
    }

    private function resolvePreferredType(string $intent): KnowledgeType
    {
        return match (true) {
            str_contains($intent, 'harga') || str_contains($intent, 'paket') => KnowledgeType::Package,
            str_contains($intent, 'keberatan') || str_contains($intent, 'objection') => KnowledgeType::Objection,
            default => KnowledgeType::Faq,
        };
    }

    private function packageRelevanceScore(KnowledgeItem $item, ?string $packageInterest, ?string $eventType): int
    {
        $haystack = mb_strtolower(trim(sprintf('%s %s', (string) $item->title, (string) $item->content)));
        $score = 1;

        if ($packageInterest !== null && $packageInterest !== '') {
            $normalizedPackageInterest = mb_strtolower(trim($packageInterest));

            if ($this->containsStandaloneKeyword($haystack, $normalizedPackageInterest)) {
                $score += 5;
            } elseif (str_contains($haystack, $normalizedPackageInterest)) {
                $score += 1;
            } else {
                $score -= 1;
            }
        }

        if ($eventType !== null && $eventType !== '') {
            $normalizedEventType = mb_strtolower(trim($eventType));

            if ($this->containsStandaloneKeyword($haystack, $normalizedEventType)) {
                $score += 6;
            } elseif (str_contains($haystack, $normalizedEventType)) {
                $score += 1;
            } else {
                $score -= 1;
            }
        }

        return $score;
    }

    private function containsStandaloneKeyword(string $haystack, string $keyword): bool
    {
        if ($haystack === '' || $keyword === '') {
            return false;
        }

        $pattern = '/(?<!\pL)' . preg_quote($keyword, '/') . '(?!\pL)/u';

        return preg_match($pattern, $haystack) === 1;
    }
}
