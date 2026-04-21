<?php

namespace App\Modules\Leads\Http\Livewire;

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Leads')]
class LeadList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleAutomation(int $leadId, TenantContext $tenantContext, LeadService $leadService): void
    {
        $lead = Lead::forTenant($tenantContext->getTenantId())->findOrFail($leadId);

        if ($lead->automation_paused) {
            $leadService->resumeAutomation($lead);
        } else {
            $leadService->pauseAutomation($lead);
        }
    }

    public function render(TenantContext $tenantContext)
    {
        $query = Lead::forTenant($tenantContext->getTenantId())
            ->with(['whatsappAgent'])
            ->orderByDesc('last_message_at');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('phone_e164', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.leads.lead-list', [
            'leads'    => $query->paginate(20),
            'statuses' => LeadStatus::cases(),
        ]);
    }
}
