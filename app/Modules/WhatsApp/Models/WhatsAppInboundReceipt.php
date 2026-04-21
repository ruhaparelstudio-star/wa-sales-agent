<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\InboundReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInboundReceipt extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_inbound_receipts';

    protected $fillable = [
        'tenant_id',
        'whatsapp_agent_id',
        'lead_id',
        'conversation_id',
        'message_id',
        'wa_message_id',
        'from_phone',
        'from_jid',
        'webhook_idempotency_key',
        'payload_hash',
        'status',
        'quoted_wa_message_id',
        'quoted_from_jid',
        'quoted_content',
        'agent_core_dispatched_at',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    protected $casts = [
        'status' => InboundReceiptStatus::class,
        'agent_core_dispatched_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
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
