<?php

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
test('user cannot access data from another tenant', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    TenantUser::create(['tenant_id' => $tenantA->id, 'user_id' => $userA->id, 'role' => TenantUserRole::VendorAdmin]);
    TenantUser::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'role' => TenantUserRole::VendorAdmin]);

    expect($userA->belongsToTenant($tenantA->id))->toBeTrue();
    expect($userA->belongsToTenant($tenantB->id))->toBeFalse();
    expect($userB->belongsToTenant($tenantB->id))->toBeTrue();
    expect($userB->belongsToTenant($tenantA->id))->toBeFalse();
});

test('super admin can access any tenant', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    expect($superAdmin->isSuperAdmin())->toBeTrue();

    $guard = app(\App\Modules\Tenancy\Services\TenantGuardService::class);
    expect(fn () => $guard->assertUserBelongsToTenant($superAdmin, $tenantA))->not->toThrow(Exception::class);
    expect(fn () => $guard->assertUserBelongsToTenant($superAdmin, $tenantB))->not->toThrow(Exception::class);
});

test('tenant user record is scoped to tenant', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $user = User::factory()->create();

    TenantUser::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'role' => TenantUserRole::VendorAdmin]);

    $count = TenantUser::where('tenant_id', $tenantB->id)->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
});
