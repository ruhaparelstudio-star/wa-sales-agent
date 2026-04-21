<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationStageTransition extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'from_stage',
        'to_stage',
        'triggered_by',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'from_stage' => ConversationStage::class,
        'to_stage'   => ConversationStage::class,
        'created_at' => 'datetime',
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
}
