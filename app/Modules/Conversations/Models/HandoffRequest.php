<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'conversation_id',
        'reason',
        'reason_detail',
        'status',
        'resolved_by',
        'resolved_at',
        'summary_for_admin',
    ];

    protected $casts = [
        'reason'      => HandoffReason::class,
        'status'      => HandoffStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', HandoffStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === HandoffStatus::Pending;
    }
}
