<?php

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Http\Livewire\BillingPage;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Livewire;


it('invoice list only shows invoices for own tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    app(TenantContext::class)->set($tenantA);

    BillingInvoice::factory()->count(2)->create(['tenant_id' => $tenantA->id]);
    BillingInvoice::factory()->count(4)->create(['tenant_id' => $tenantB->id]);

    $user = \App\Models\User::factory()->create();

    Livewire::actingAs($user)
        ->test(BillingPage::class)
        ->assertViewHas('invoices', function ($invoices) {
            return $invoices->total() === 2;
        });
});
