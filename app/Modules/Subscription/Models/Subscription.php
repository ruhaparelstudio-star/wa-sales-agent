<?php

namespace App\Modules\Subscription\Models;

use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'grace_ends_at',
    ];

    protected $casts = [
        'status'         => SubscriptionStatus::class,
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
        'trial_ends_at'  => 'datetime',
        'grace_ends_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', SubscriptionStatus::Expired);
    }

    public function scopeGrace($query)
    {
        return $query->where('status', SubscriptionStatus::GracePeriod);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::GracePeriod;
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired;
    }

    public function isTrialing(): bool
    {
        return $this->trial_ends_at !== null && now()->lessThanOrEqualTo($this->trial_ends_at);
    }

    public function daysUntilExpiry(): int
    {
        if (now()->greaterThan($this->ends_at)) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay());
    }
}
