<?php

namespace App\Modules\Leads\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadMemory extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'name',
        'event_date',
        'event_location',
        'budget_min',
        'budget_max',
        'service_type',
        'guest_count',
        'preferred_packages',
        'objections',
        'custom_fields',
    ];

    protected $casts = [
        'event_date' => 'date',
        'budget_min' => 'integer',
        'budget_max' => 'integer',
        'guest_count' => 'integer',
        'preferred_packages' => 'array',
        'objections' => 'array',
        'custom_fields' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
