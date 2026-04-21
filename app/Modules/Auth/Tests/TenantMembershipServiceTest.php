<?php

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Services\TenantMembershipService;
use App\Modules\Tenancy\Models\Tenant;
beforeEach(function () {
    $this->service = app(TenantMembershipService::class);
    $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
});

test('add user to tenant creates tenant_user record', function () {
    $user = User::factory()->create();

    $tenantUser = $this->service->addUserToTenant($this->tenant, $user, TenantUserRole::VendorAdmin);

    expect($tenantUser->tenant_id)->toBe($this->tenant->id);
    expect($tenantUser->user_id)->toBe($user->id);
    expect($tenantUser->role)->toBe(TenantUserRole::VendorAdmin);
});

test('add user to tenant is idempotent', function () {
    $user = User::factory()->create();

    $this->service->addUserToTenant($this->tenant, $user, TenantUserRole::VendorAdmin);
    $this->service->addUserToTenant($this->tenant, $user, TenantUserRole::VendorAdmin);

    expect(\App\Modules\Auth\Models\TenantUser::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('get user role returns correct role', function () {
    $admin = User::factory()->create();
    $staff = User::factory()->create();

    $this->service->addUserToTenant($this->tenant, $admin, TenantUserRole::VendorAdmin);
    $this->service->addUserToTenant($this->tenant, $staff, TenantUserRole::VendorStaff);

    expect($this->service->getUserRole($this->tenant, $admin))->toBe(TenantUserRole::VendorAdmin);
    expect($this->service->getUserRole($this->tenant, $staff))->toBe(TenantUserRole::VendorStaff);
});

test('get user role returns null for non-member', function () {
    $user = User::factory()->create();

    expect($this->service->getUserRole($this->tenant, $user))->toBeNull();
});

test('get admins for tenant returns only admins', function () {
    $admin1 = User::factory()->create();
    $admin2 = User::factory()->create();
    $staff = User::factory()->create();

    $this->service->addUserToTenant($this->tenant, $admin1, TenantUserRole::VendorAdmin);
    $this->service->addUserToTenant($this->tenant, $admin2, TenantUserRole::VendorAdmin);
    $this->service->addUserToTenant($this->tenant, $staff, TenantUserRole::VendorStaff);

    $admins = $this->service->getAdminsForTenant($this->tenant);

    expect($admins)->toHaveCount(2);
    expect($admins->pluck('id')->toArray())->toContain($admin1->id, $admin2->id);
    expect($admins->pluck('id')->toArray())->not->toContain($staff->id);
});
