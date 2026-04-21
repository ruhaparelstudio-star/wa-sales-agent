<?php

namespace App\Modules\Knowledge\Models;

use App\Models\User;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'proposed_title',
        'proposed_content',
        'proposed_type',
        'source_note',
        'status',
        'reviewed_by',
        'reviewed_at',
        'promoted_to_item_id',
    ];

    protected $casts = [
        'status'      => KnowledgeStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function promotedItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'promoted_to_item_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KnowledgeStatus::Pending->value);
    }
}
