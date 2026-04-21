<?php

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeCandidate;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeCandidateService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;


beforeEach(fn () => Cache::flush());

function makeCandidateService(): KnowledgeCandidateService
{
    return new KnowledgeCandidateService();
}

test('submit membuat candidate dengan status pending', function () {
    $tenant = Tenant::factory()->create();

    $candidate = makeCandidateService()->submit($tenant, null, [
        'proposed_title'   => 'FAQ Test',
        'proposed_content' => 'Isi konten FAQ.',
        'proposed_type'    => KnowledgeType::Faq->value,
    ]);

    expect($candidate)->toBeInstanceOf(KnowledgeCandidate::class)
        ->and($candidate->status)->toBe(KnowledgeStatus::Pending)
        ->and($candidate->tenant_id)->toBe($tenant->id);
});

test('approve promote candidate ke knowledge_items', function () {
    $tenant    = Tenant::factory()->create();
    $user      = User::factory()->create();
    $candidate = KnowledgeCandidate::factory()->forTenant($tenant)->create([
        'proposed_type'    => KnowledgeType::Package->value,
        'proposed_title'   => 'Paket Silver',
        'proposed_content' => 'Deskripsi paket silver.',
    ]);

    $item = makeCandidateService()->approve($candidate, $user);

    expect($item)->toBeInstanceOf(KnowledgeItem::class)
        ->and($item->title)->toBe('Paket Silver')
        ->and($item->type)->toBe(KnowledgeType::Package)
        ->and($item->tenant_id)->toBe($tenant->id);

    $candidate->refresh();
    expect($candidate->status)->toBe(KnowledgeStatus::Approved)
        ->and($candidate->promoted_to_item_id)->toBe($item->id)
        ->and($candidate->reviewed_by)->toBe($user->id)
        ->and($candidate->reviewed_at)->not->toBeNull();
});

test('reject mengubah status menjadi rejected', function () {
    $tenant    = Tenant::factory()->create();
    $user      = User::factory()->create();
    $candidate = KnowledgeCandidate::factory()->forTenant($tenant)->pending()->create();

    makeCandidateService()->reject($candidate, $user);

    $candidate->refresh();
    expect($candidate->status)->toBe(KnowledgeStatus::Rejected)
        ->and($candidate->reviewed_by)->toBe($user->id)
        ->and($candidate->reviewed_at)->not->toBeNull();
});

test('getPending hanya return candidate pending milik tenant', function () {
    $tenant = Tenant::factory()->create();

    KnowledgeCandidate::factory()->count(2)->forTenant($tenant)->pending()->create();
    KnowledgeCandidate::factory()->forTenant($tenant)->approved()->create();
    KnowledgeCandidate::factory()->pending()->create(); // tenant lain

    $pending = makeCandidateService()->getPending($tenant);

    expect($pending)->toHaveCount(2)
        ->and($pending->pluck('tenant_id')->unique()->first())->toBe($tenant->id);
});
