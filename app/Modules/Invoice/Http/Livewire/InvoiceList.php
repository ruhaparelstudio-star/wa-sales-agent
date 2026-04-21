<?php

namespace App\Modules\Invoice\Http\Livewire;

use App\Modules\Invoice\Actions\SendClientInvoiceAction;
use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Invoices')]
class InvoiceList extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public string $dateFrom = '';
    public string $dateTo   = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function send(int $invoiceId, TenantContext $tenantContext, SendClientInvoiceAction $action): void
    {
        $action->execute($invoiceId, $tenantContext->get());
        session()->flash('success', 'Invoice queued for sending.');
    }

    public function markPaid(int $invoiceId, TenantContext $tenantContext, ClientInvoiceService $service): void
    {
        $tenant  = $tenantContext->get();
        $invoice = ClientInvoice::where('tenant_id', $tenant->id)->findOrFail($invoiceId);
        $service->markAsPaid($invoice);
    }

    public function render(TenantContext $tenantContext)
    {
        $tenantId = $tenantContext->getTenantId();

        $query = ClientInvoice::where('tenant_id', $tenantId)
            ->with('lead')
            ->orderByDesc('created_at');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->whereHas('lead', fn ($q) => $q->where('name', 'like', "%{$this->search}%"));
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        return view('livewire.invoice.invoice-list', [
            'invoices' => $query->paginate(20),
            'statuses' => ClientInvoiceStatus::cases(),
        ]);
    }
}
