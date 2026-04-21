<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\PairingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppPairing extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'whatsapp_pairings';

    protected $fillable = [
        'tenant_id',
        'whatsapp_agent_id',
        'status',
        'pairing_token',
        'expires_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'status'       => PairingStatus::class,
        'expires_at'   => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAgent::class, 'whatsapp_agent_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PairingStatus::Pending->value);
    }
}
