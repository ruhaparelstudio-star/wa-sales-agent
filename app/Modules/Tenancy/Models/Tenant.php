<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Auth\Models\TenantUser;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_active', 'settings', 'tone_profile', 'primary_service_catalog_id'];

    protected $casts = [
        'is_active'    => 'boolean',
        'settings'     => 'array',
        'tone_profile' => 'array',
    ];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function primaryServiceCatalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'primary_service_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isExpired(): bool
    {
        // Will check subscription expiry in Phase Subscription
        return false;
    }

    public function primaryServiceSlug(): ?string
    {
        return $this->primaryServiceCatalog?->slug;
    }

    public function primaryServiceName(): ?string
    {
        return $this->primaryServiceCatalog?->name;
    }
}
