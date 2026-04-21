<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\FormType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadBookingData extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'form_type',
        'field_key',
        'field_value',
    ];

    protected $casts = [
        'form_type' => FormType::class,
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForLead(Builder $query, int $leadId): Builder
    {
        return $query->where('lead_id', $leadId);
    }

    public function scopeOfType(Builder $query, FormType $type): Builder
    {
        return $query->where('form_type', $type->value);
    }
}
