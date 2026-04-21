<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;

it('renders the login page without runtime errors', function () {
    $response = $this->get(route('auth.login'));

    $response
        ->assertOk()
        ->assertSee('Sales Agent WA');
});

it('responds successfully to a head request on the login page', function () {
    $response = $this->head(route('auth.login'));

    $response->assertOk();
});

it('allows the seeded super admin to log in, access the super admin area, and log out', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->get(route('auth.login'))->assertOk();

    $loginResponse = $this->post(route('auth.login.submit'), [
        '_token' => session()->token(),
        'email' => 'superadmin@localhost',
        'password' => 'password',
    ]);

    $loginResponse->assertRedirect(route('superadmin.tenants.index'));

    $this->followRedirects($loginResponse)
        ->assertOk()
        ->assertSee('Daftar Tenant');

    $this->post(route('auth.logout'), [
        '_token' => session()->token(),
    ])
        ->assertRedirect(route('auth.login'));

    $this->get(route('auth.login'))
        ->assertOk()
        ->assertSee('Sales Agent WA');
});

it('allows a tenant user to open tenant pages without missing tenant context runtime errors', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $tenant->tenantUsers()->create([
        'user_id' => $user->id,
        'role' => 'vendor_admin',
    ]);

    $this->actingAs($user)
        ->get(route('whatsapp-agents.index'))
        ->assertOk()
        ->assertSee('WhatsApp Agents');
});
