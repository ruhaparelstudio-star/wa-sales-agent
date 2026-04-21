<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSummary extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'summary_text',
        'last_summarized_at',
        'message_count_at_summary',
    ];

    protected $casts = [
        'last_summarized_at' => 'datetime',
        'message_count_at_summary' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
