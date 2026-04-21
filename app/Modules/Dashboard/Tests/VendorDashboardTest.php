<?php

use App\Modules\Dashboard\Http\Livewire\VendorDashboard;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Livewire;


it('only shows metrics for own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Lead::factory()->create(['tenant_id' => $tenantA->id, 'status' => LeadStatus::Hot]);
    Lead::factory()->create(['tenant_id' => $tenantB->id, 'status' => LeadStatus::Hot]);

    $user = \App\Models\User::factory()->create();
    app(TenantContext::class)->set($tenantA);

    $component = Livewire::actingAs($user)->test(VendorDashboard::class);

    $metrics = app(\App\Modules\Dashboard\Services\DashboardMetricsService::class)->getMetrics($tenantA);

    expect($metrics['hot_leads_count'])->toBe(1);
});
