<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationState extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'lead_id',
        'current_stage',
        'current_intent',
        'intent_confidence',
        'interpretation_source',
        'lead_temperature',
        'filled_slots',
        'unresolved_questions',
        'last_user_message',
        'last_agent_message',
        'last_agent_question',
        'last_answered_topic',
        'next_best_action',
        'last_tool_result_summary',
    ];

    protected $casts = [
        'intent_confidence' => 'float',
        'filled_slots' => 'array',
        'unresolved_questions' => 'array',
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
}
