<?php

namespace App\Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'max_agents',
        'monthly_token_soft_cap',
        'features',
        'price',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'features'               => 'array',
        'is_active'              => 'boolean',
        'monthly_token_soft_cap' => 'integer',
        'max_agents'             => 'integer',
        'sort_order'             => 'integer',
        'price'                  => 'decimal:2',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
