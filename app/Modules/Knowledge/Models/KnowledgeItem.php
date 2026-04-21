<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'title',
        'content',
        'tags',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'type'       => KnowledgeType::class,
        'tags'       => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(KnowledgeCandidate::class, 'promoted_to_item_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, KnowledgeType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
