<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\ConversationStageTransition;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'whatsapp_agent_id',
        'status',
        'stage',
        'stage_updated_at',
        'asked_fields',
        'next_expected_field',
        'stage_transition_count',
        'is_human_takeover',
        'closed_at',
    ];

    protected $casts = [
        'status'                 => ConversationStatus::class,
        'stage'                  => ConversationStage::class,
        'stage_updated_at'       => 'datetime',
        'asked_fields'           => 'array',
        'stage_transition_count' => 'integer',
        'is_human_takeover'      => 'boolean',
        'closed_at'              => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function whatsappAgent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAgent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(ConversationSummary::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(ConversationState::class);
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
        return $query->where('status', ConversationStatus::Active->value);
    }

    public function scopeHandoff(Builder $query): Builder
    {
        return $query->where('status', ConversationStatus::Handoff->value);
    }

    public function isActive(): bool
    {
        return $this->status === ConversationStatus::Active;
    }

    public function isHandoff(): bool
    {
        return $this->status === ConversationStatus::Handoff;
    }

    /**
     * @return list<string>
     */
    public function askedFields(): array
    {
        $fields = $this->asked_fields ?? [];
        return is_array($fields) ? array_values(array_unique(array_filter(array_map('strval', $fields)))) : [];
    }

    public function stageEnum(): ConversationStage
    {
        return ConversationStage::coerce($this->stage)
            ?? ConversationStage::NewLead;
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ConversationStageTransition::class);
    }
}
