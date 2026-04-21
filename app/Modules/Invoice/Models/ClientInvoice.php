<?php

namespace App\Modules\Invoice\Models;

use App\Models\User;
use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Enums\ClientInvoiceType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'whatsapp_agent_id',
        'invoice_number',
        'invoice_type',
        'status',
        'amount',
        'currency',
        'due_date',
        'pdf_path',
        'intro_message',
        'wa_message_id',
        'sent_at',
        'delivered_at',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'invoice_type' => ClientInvoiceType::class,
        'status'       => ClientInvoiceStatus::class,
        'amount'       => 'decimal:2',
        'due_date'     => 'date',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function whatsappAgent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAgent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientInvoiceItem::class, 'invoice_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForLead(Builder $query, int $leadId): Builder
    {
        return $query->where('lead_id', $leadId);
    }

    /** Invoices that have been sent but not yet paid (relevant for payment proof detection). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ClientInvoiceStatus::Sent->value,
            ClientInvoiceStatus::Delivered->value,
            ClientInvoiceStatus::Viewed->value,
        ]);
    }

    public function isDraft(): bool
    {
        return $this->status === ClientInvoiceStatus::Draft;
    }

    public function isSent(): bool
    {
        return $this->status === ClientInvoiceStatus::Sent;
    }

    public function isPaid(): bool
    {
        return $this->status === ClientInvoiceStatus::Paid;
    }
}
