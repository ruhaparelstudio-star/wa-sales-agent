<?php

namespace App\Modules\Leads\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'event_date',
        'event_location',
        'budget_min',
        'budget_max',
        'service_type',
        'guest_count',
        'notes',
    ];

    protected $casts = [
        'event_date' => 'date',
        'budget_min' => 'integer',
        'budget_max' => 'integer',
        'guest_count' => 'integer',
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
