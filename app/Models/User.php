<?php

namespace App\Models;

use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = ['name', 'email', 'password', 'is_super_admin'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TenantInvitation::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenantUsers()->where('tenant_id', $tenantId)->exists();
    }

    public function tenantRole(int $tenantId): ?TenantUserRole
    {
        $role = $this->tenantUsers()
            ->where('tenant_id', $tenantId)
            ->value('role');

        if ($role instanceof TenantUserRole) {
            return $role;
        }

        return $role !== null ? TenantUserRole::from($role) : null;
    }

    public function hasTenantPermission(string $permission, ?int $tenantId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($tenantId === null) {
            return false;
        }

        return in_array($permission, $this->tenantRole($tenantId)?->permissions() ?? [], true);
    }
}
