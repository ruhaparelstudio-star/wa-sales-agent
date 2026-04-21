<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingField extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'template_id',
        'field_key',
        'label',
        'field_type',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'field_type'  => BookingFieldType::class,
        'options'     => 'array',
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(BookingFormTemplate::class, 'template_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }
}
