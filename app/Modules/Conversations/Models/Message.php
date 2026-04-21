<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Enums\MessageStatus;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'lead_id',
        'direction',
        'message_type',
        'content',
        'media_url',
        'media_mime',
        'media_filename',
        'wa_message_id',
        'provider_idempotency_key',
        'quoted_wa_message_id',
        'quoted_from_jid',
        'quoted_content',
        'status',
        'is_from_ai',
    ];

    protected $casts = [
        'direction'    => MessageDirection::class,
        'message_type' => MessageType::class,
        'status'       => MessageStatus::class,
        'is_from_ai'   => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Inbound->value);
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Outbound->value);
    }

    public function scopeText(Builder $query): Builder
    {
        return $query->where('message_type', MessageType::Text->value);
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    public function isMedia(): bool
    {
        return $this->message_type !== MessageType::Text && $this->message_type !== MessageType::System;
    }
}
