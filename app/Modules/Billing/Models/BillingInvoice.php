<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'invoice_number',
        'amount',
        'status',
        'due_date',
        'period_start',
        'period_end',
        'proof_path',
        'proof_uploaded_at',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'status'            => BillingInvoiceStatus::class,
        'due_date'          => 'date',
        'period_start'      => 'date',
        'period_end'        => 'date',
        'proof_uploaded_at' => 'datetime',
        'approved_at'       => 'datetime',
        'amount'            => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', BillingInvoiceStatus::Unpaid);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
