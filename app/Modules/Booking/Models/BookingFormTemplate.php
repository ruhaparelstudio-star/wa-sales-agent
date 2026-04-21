<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\FormType;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingFormTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'form_type',
        'name',
        'is_active',
    ];

    protected $casts = [
        'form_type' => FormType::class,
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(BookingField::class, 'template_id')->orderBy('sort_order');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, FormType $type): Builder
    {
        return $query->where('form_type', $type->value);
    }
}
