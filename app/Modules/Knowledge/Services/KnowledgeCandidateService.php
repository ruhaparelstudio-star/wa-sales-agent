<?php

namespace App\Modules\Knowledge\Services;

use App\Models\User;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Models\KnowledgeCandidate;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KnowledgeCandidateService
{
    public function submit(Tenant $tenant, ?Conversation $conv, array $data): KnowledgeCandidate
    {
        return KnowledgeCandidate::create([
            'tenant_id'        => $tenant->id,
            'conversation_id'  => $conv?->id,
            'proposed_title'   => $data['proposed_title'],
            'proposed_content' => $data['proposed_content'],
            'proposed_type'    => $data['proposed_type'],
            'source_note'      => $data['source_note'] ?? null,
            'status'           => KnowledgeStatus::Pending,
        ]);
    }

    public function approve(KnowledgeCandidate $candidate, User $user): KnowledgeItem
    {
        return DB::transaction(function () use ($candidate, $user) {
            $item = KnowledgeItem::create([
                'tenant_id'  => $candidate->tenant_id,
                'type'       => $candidate->proposed_type,
                'title'      => $candidate->proposed_title,
                'content'    => $candidate->proposed_content,
                'is_active'  => true,
                'sort_order' => 0,
            ]);

            $candidate->update([
                'status'              => KnowledgeStatus::Approved,
                'reviewed_by'         => $user->id,
                'reviewed_at'         => now(),
                'promoted_to_item_id' => $item->id,
            ]);

            $this->invalidateCache($candidate->tenant_id);

            return $item;
        });
    }

    public function reject(KnowledgeCandidate $candidate, User $user): void
    {
        $candidate->update([
            'status'      => KnowledgeStatus::Rejected,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }

    public function getPending(Tenant $tenant): Collection
    {
        return KnowledgeCandidate::forTenant($tenant->id)->pending()->get();
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
