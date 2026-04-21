<?php

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Register a test route protected by tenant middleware
    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/tenant-route', function () {
        $context = app(\App\Modules\Tenancy\Services\TenantContext::class);
        return response()->json(['tenant_id' => $context->getTenantId()]);
    });
});

test('unauthenticated request to tenant route redirects to login', function () {
    $this->get('/_test/tenant-route')
        ->assertRedirect(route('auth.login'));
});

test('authenticated user without tenant membership returns 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/_test/tenant-route')
        ->assertStatus(403);
});

test('authenticated user with valid tenant membership passes through', function () {
    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
    $user = User::factory()->create();
    TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => TenantUserRole::VendorAdmin->value]);

    $response = $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->get('/_test/tenant-route');

    $response->assertOk();
    $response->assertJson(['tenant_id' => $tenant->id]);
});

test('super admin bypasses tenant guard', function () {
    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $response = $this->actingAs($superAdmin)
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->get('/_test/tenant-route');

    $response->assertOk();
    $response->assertJson(['tenant_id' => $tenant->id]);
});

test('super admin without active tenant context is redirected to superadmin area', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get('/_test/tenant-route')
        ->assertRedirect(route('superadmin.tenants.index'));
});
