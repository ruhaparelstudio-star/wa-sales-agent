<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\OutboundDispatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppOutboundDispatch extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_outbound_dispatches';

    protected $fillable = [
        'tenant_id',
        'whatsapp_agent_id',
        'lead_id',
        'conversation_id',
        'message_id',
        'recipient',
        'message_type',
        'idempotency_key',
        'payload_hash',
        'provider_message_id',
        'status',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'status' => OutboundDispatchStatus::class,
        'sent_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAgent::class, 'whatsapp_agent_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
