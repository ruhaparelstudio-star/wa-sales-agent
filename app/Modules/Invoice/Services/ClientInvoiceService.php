<?php

namespace App\Modules\Invoice\Services;

use App\Models\User;
use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Enums\ClientInvoiceType;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ClientInvoiceService
{
    public function createFromItems(Tenant $tenant, Lead $lead, User $creator, array $items, array $meta): ClientInvoice
    {
        $total = collect($items)->sum(fn ($item) => $item['quantity'] * $item['unit_price']);

        $invoice = ClientInvoice::create([
            'tenant_id'     => $tenant->id,
            'lead_id'       => $lead->id,
            'invoice_number'=> $this->generateInvoiceNumber($tenant),
            'invoice_type'  => ClientInvoiceType::Created,
            'status'        => ClientInvoiceStatus::Draft,
            'amount'        => $total,
            'currency'      => $meta['currency'] ?? 'IDR',
            'due_date'      => $meta['due_date'] ?? null,
            'intro_message' => $meta['intro_message'] ?? null,
            'notes'         => $meta['notes'] ?? null,
            'created_by'    => $creator->id,
        ]);

        foreach ($items as $index => $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
                'sort_order'  => $item['sort_order'] ?? $index,
            ]);
        }

        return $invoice->load('items');
    }

    public function attachUploadedPdf(Tenant $tenant, Lead $lead, User $creator, UploadedFile $file, array $meta): ClientInvoice
    {
        $invoice = ClientInvoice::create([
            'tenant_id'     => $tenant->id,
            'lead_id'       => $lead->id,
            'invoice_number'=> $this->generateInvoiceNumber($tenant),
            'invoice_type'  => ClientInvoiceType::Uploaded,
            'status'        => ClientInvoiceStatus::Draft,
            'currency'      => 'IDR',
            'due_date'      => $meta['due_date'] ?? null,
            'intro_message' => $meta['intro_message'] ?? null,
            'created_by'    => $creator->id,
        ]);

        $path = "tenants/{$tenant->id}/invoices/{$invoice->id}.pdf";
        Storage::put($path, $file->getContent());

        $invoice->update(['pdf_path' => $path]);

        return $invoice->fresh();
    }

    public function getInvoicesForLead(Tenant $tenant, Lead $lead): Collection
    {
        return ClientInvoice::forTenant($tenant->id)
            ->forLead($lead->id)
            ->with('items')
            ->latest()
            ->get();
    }

    public function getInvoiceForTenant(int $invoiceId, Tenant $tenant): ClientInvoice
    {
        return ClientInvoice::forTenant($tenant->id)->findOrFail($invoiceId);
    }

    public function markAsPaid(ClientInvoice $invoice): void
    {
        $invoice->update([
            'status'  => ClientInvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    private function generateInvoiceNumber(Tenant $tenant): string
    {
        $ym    = now()->format('Ym');
        $count = ClientInvoice::where('tenant_id', $tenant->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return sprintf('INV-%d-%s-%04d', $tenant->id, $ym, $count + 1);
    }
}
