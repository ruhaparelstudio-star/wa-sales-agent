<?php

namespace App\Modules\AgentCore\Models;

use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_id',
        'trace_id',
        'mode',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'prompt_hash',
        'response_excerpt',
        'model',
    ];

    protected $casts = [
        'mode' => LlmMode::class,
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'latency_ms' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeMode(Builder $query, LlmMode $mode): Builder
    {
        return $query->where('mode', $mode->value);
    }
}
