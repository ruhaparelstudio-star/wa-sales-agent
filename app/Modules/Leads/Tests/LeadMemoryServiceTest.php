<?php

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeLeadMemoryService(): LeadMemoryService
{
    return new LeadMemoryService();
}

test('upsert creates lead memory on first call', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeLeadMemoryService()->upsert($lead, [
        'name'          => 'Budi Santoso',
        'event_date'    => '2026-12-12',
        'service_type'  => 'foto+video',
    ]);

    $memory = LeadMemory::where('lead_id', $lead->id)->first();

    expect($memory)->not->toBeNull()
        ->and($memory->name)->toBe('Budi Santoso')
        ->and($memory->service_type)->toBe('foto+video');
});

test('upsert normalizes indonesian natural-language event date before saving', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeLeadMemoryService()->upsert($lead, [
        'event_date' => '30 desember',
    ]);

    $memory = $lead->fresh()->memory;

    expect($memory)->not->toBeNull()
        ->and($memory->event_date?->toDateString())->toBe(now()->format('Y') . '-12-30');
});

test('upsert does not overwrite existing fields with null', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    // First upsert establishes values
    makeLeadMemoryService()->upsert($lead, ['name' => 'Siti', 'event_location' => 'Jakarta']);

    // Second upsert with null name — should preserve 'Siti'
    makeLeadMemoryService()->upsert($lead, ['name' => null, 'guest_count' => 150]);

    $memory = $lead->fresh()->memory;

    expect($memory->name)->toBe('Siti')
        ->and($memory->event_location)->toBe('Jakarta')
        ->and($memory->guest_count)->toBe(150);
});

test('upsert skips invalid event date but still saves other valid fields', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeLeadMemoryService()->upsert($lead, [
        'name' => 'Siti',
        'event_date' => 'secepatnya ya kak',
    ]);

    $memory = $lead->fresh()->memory;

    expect($memory)->not->toBeNull()
        ->and($memory->name)->toBe('Siti')
        ->and($memory->event_date)->toBeNull();
});

test('upsert merges json array fields instead of overwriting', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeLeadMemoryService()->upsert($lead, ['objections' => ['harga mahal']]);
    makeLeadMemoryService()->upsert($lead, ['objections' => ['tanggal bentrok']]);

    $memory = $lead->fresh()->memory;

    expect($memory->objections)->toContain('harga mahal')
        ->and($memory->objections)->toContain('tanggal bentrok');
});

test('getSnapshot returns only non-null fields', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeLeadMemoryService()->upsert($lead, [
        'name'       => 'Andi',
        'guest_count'=> 200,
    ]);

    $snapshot = makeLeadMemoryService()->getSnapshot($lead->fresh()->load('memory'));

    expect($snapshot)->toHaveKey('name')
        ->and($snapshot)->toHaveKey('guest_count')
        ->and($snapshot)->not->toHaveKey('event_date')
        ->and($snapshot)->not->toHaveKey('budget_min');
});
