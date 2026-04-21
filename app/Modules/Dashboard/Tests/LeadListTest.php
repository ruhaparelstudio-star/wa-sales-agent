<?php

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Http\Livewire\LeadList;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Livewire;


it('filters leads by status', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Hot]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);

    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadList::class)
        ->set('statusFilter', LeadStatus::Hot->value)
        ->assertViewHas('leads', function ($leads) {
            return $leads->total() === 1;
        });
});

it('paginates leads', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    Lead::factory()->count(25)->create(['tenant_id' => $tenant->id]);

    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadList::class)
        ->assertViewHas('leads', function ($leads) {
            return $leads->perPage() === 20 && $leads->total() === 25;
        });
});

it('does not show leads from other tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    app(TenantContext::class)->set($tenantA);

    Lead::factory()->count(3)->create(['tenant_id' => $tenantA->id]);
    Lead::factory()->count(5)->create(['tenant_id' => $tenantB->id]);

    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadList::class)
        ->assertViewHas('leads', function ($leads) {
            return $leads->total() === 3;
        });
});
