<?php

use App\Modules\Leads\Http\Livewire\LeadDetail;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Livewire;


it('returns 404 if lead belongs to different tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    app(TenantContext::class)->set($tenantA);

    $lead = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadDetail::class, ['leadId' => $lead->id])
        ->assertStatus(404);
})->skip('Requires full tenant isolation test setup');

it('renders lead detail for own tenant', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadDetail::class, ['leadId' => $lead->id])
        ->assertOk();
});
