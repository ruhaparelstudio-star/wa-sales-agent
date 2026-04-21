<?php

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\ServiceCatalog;
use App\Modules\Tenancy\Models\Tenant;

test('lead memory snapshot falls back to tenant primary service', function () {
    $service = ServiceCatalog::query()->create([
        'name' => 'Dokumentasi',
        'slug' => 'dokumentasi',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $tenant = Tenant::factory()->create([
        'primary_service_catalog_id' => $service->id,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $snapshot = app(LeadMemoryService::class)->getSnapshot($lead->fresh());

    expect($snapshot['service_type'])->toBe('dokumentasi');
});

test('qualification next expected field no longer starts from service_type', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    $next = app(ConversationStageService::class)->nextExpectedField($conv, []);

    expect($next)->toBe('event_date');
});

test('vendor admin can update tenant primary service from profile page', function () {
    $service = ServiceCatalog::query()->create([
        'name' => 'Dekorasi',
        'slug' => 'dekorasi',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantUser::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => TenantUserRole::VendorAdmin->value,
    ]);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->post('/profile', [
            'primary_service_catalog_id' => $service->id,
        ])
        ->assertRedirect(route('tenant-profile.edit'));

    expect($tenant->fresh()->primary_service_catalog_id)->toBe($service->id);
});
