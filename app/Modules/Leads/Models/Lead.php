<?php

namespace App\Modules\Leads\Models;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'whatsapp_agent_id',
        'phone_e164',
        'whatsapp_jid',
        'name',
        'status',
        'interest_score',
        'risk_score',
        'automation_paused',
        'last_message_at',
    ];

    protected $casts = [
        'status'            => LeadStatus::class,
        'interest_score'    => 'integer',
        'risk_score'        => 'integer',
        'automation_paused' => 'boolean',
        'last_message_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function whatsappAgent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAgent::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(LeadProfile::class);
    }

    public function memory(): HasOne
    {
        return $this->hasOne(LeadMemory::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function handoffRequests(): HasMany
    {
        return $this->hasMany(HandoffRequest::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('automation_paused', false)
            ->whereNotIn('status', [LeadStatus::ClosedWon->value, LeadStatus::ClosedLost->value]);
    }

    public function scopeHot(Builder $query): Builder
    {
        return $query->whereIn('status', [LeadStatus::Hot->value, LeadStatus::ReadyForHuman->value]);
    }

    public function isActive(): bool
    {
        return ! $this->automation_paused
            && ! in_array($this->status, [LeadStatus::ClosedWon, LeadStatus::ClosedLost]);
    }

    public function preferredWhatsAppRecipient(): string
    {
        return $this->whatsapp_jid ?: $this->phone_e164;
    }
}
