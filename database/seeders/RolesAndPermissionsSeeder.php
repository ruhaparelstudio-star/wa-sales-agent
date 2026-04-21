<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'manage-agents',
            'manage-knowledge',
            'manage-billing',
            'view-leads',
            'manage-leads',
            'manage-invoices',
            'takeover-chat',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);

        $vendorAdmin = Role::firstOrCreate(['name' => 'vendor_admin']);
        $vendorAdmin->syncPermissions([
            'manage-agents', 'manage-knowledge', 'manage-billing',
            'view-leads', 'manage-leads', 'manage-invoices', 'takeover-chat',
        ]);

        $vendorStaff = Role::firstOrCreate(['name' => 'vendor_staff']);
        $vendorStaff->syncPermissions([
            'view-leads', 'manage-leads', 'takeover-chat',
        ]);

        // Create a default super admin user if not exists
        $admin = User::firstOrCreate(
            ['email' => 'superadmin@localhost'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('password'),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole($superAdmin);
    }
}
