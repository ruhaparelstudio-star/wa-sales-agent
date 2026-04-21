<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;


it('vendor user cannot access superadmin routes', function () {
    $tenant = Tenant::factory()->create();
    $user   = User::factory()->create();
    app(TenantContext::class)->set($tenant);

    $this->actingAs($user)
        ->get('/superadmin/tenants')
        ->assertForbidden();
});

it('superadmin can access superadmin tenants page', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('super_admin');

    $this->actingAs($user)
        ->get('/superadmin/tenants')
        ->assertOk();
})->skip('Requires super_admin permission to be seeded');

it('superadmin can access usage page', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)
        ->get('/superadmin/usage')
        ->assertOk();
})->skip('Requires super_admin role to be seeded');
