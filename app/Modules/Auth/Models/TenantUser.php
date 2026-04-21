<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUser extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'role'];

    protected $casts = [
        'role' => TenantUserRole::class,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
