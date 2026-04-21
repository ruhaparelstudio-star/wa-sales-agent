<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAgent extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'whatsapp_agents';

    protected $fillable = [
        'tenant_id',
        'phone_number',
        'display_name',
        'status',
        'is_default',
        'last_connected_at',
        'last_disconnected_at',
    ];

    protected $casts = [
        'status'               => AgentStatus::class,
        'is_default'           => 'boolean',
        'last_connected_at'    => 'datetime',
        'last_disconnected_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pairings(): HasMany
    {
        return $this->hasMany(WhatsAppPairing::class);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', AgentStatus::Connected->value);
    }

    public function scopeDisconnected(Builder $query): Builder
    {
        return $query->where('status', AgentStatus::Disconnected->value);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isConnected(): bool
    {
        return $this->status === AgentStatus::Connected;
    }
}
