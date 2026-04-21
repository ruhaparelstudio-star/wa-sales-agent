<?php

namespace App\Modules\AgentCore\Models;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationTurnLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_id',
        'trace_id',
        'intent',
        'intent_confidence',
        'extracted_slots',
        'stage_before',
        'stage_after',
        'next_best_action',
        'fallback_used',
        'fallback_reason',
        'tool_used',
        'response_type',
        'reply_excerpt',
        'evaluator_score',
        'latency_ms_total',
    ];

    protected $casts = [
        'extracted_slots' => 'array',
        'evaluator_score' => 'array',
        'fallback_used' => 'boolean',
        'intent_confidence' => 'float',
        'latency_ms_total' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
