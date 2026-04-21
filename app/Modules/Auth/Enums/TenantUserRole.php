<?php

namespace App\Modules\Auth\Enums;

enum TenantUserRole: string
{
    case VendorAdmin = 'vendor_admin';
    case VendorStaff = 'vendor_staff';

    public function label(): string
    {
        return match($this) {
            self::VendorAdmin => 'Vendor Admin',
            self::VendorStaff => 'Vendor Staff',
        };
    }

    public function permissions(): array
    {
        return match($this) {
            self::VendorAdmin => [
                'manage-agents', 'manage-knowledge', 'manage-billing',
                'view-leads', 'manage-leads', 'manage-invoices', 'takeover-chat',
            ],
            self::VendorStaff => [
                'view-leads', 'manage-leads', 'takeover-chat',
            ],
        };
    }
}
