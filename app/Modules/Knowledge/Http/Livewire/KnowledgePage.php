<?php

namespace App\Modules\Knowledge\Http\Livewire;

use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeCandidate;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeCandidateService;
use App\Modules\Knowledge\Services\KnowledgeService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Knowledge Base')]
class KnowledgePage extends Component
{
    use WithPagination;

    public string  $activeTab  = 'items';
    public string  $typeFilter = '';

    // Form modal state
    public bool    $showForm   = false;
    public ?int    $editingId  = null;
    public string  $formTitle  = '';
    public string  $formType   = '';
    public string  $formContent = '';
    public bool    $formActive = true;

    public function openCreateForm(): void
    {
        $this->reset(['editingId', 'formTitle', 'formType', 'formContent', 'formActive']);
        $this->showForm = true;
    }

    public function openEditForm(int $id, TenantContext $tenantContext): void
    {
        $item = KnowledgeItem::forTenant($tenantContext->getTenantId())->findOrFail($id);
        $this->editingId    = $id;
        $this->formTitle    = $item->title;
        $this->formType     = $item->type->value;
        $this->formContent  = $item->content;
        $this->formActive   = $item->is_active;
        $this->showForm     = true;
    }

    public function saveItem(TenantContext $tenantContext, KnowledgeService $service): void
    {
        $this->validate([
            'formTitle'   => 'required|string|max:255',
            'formType'    => 'required|in:' . implode(',', array_column(KnowledgeType::cases(), 'value')),
            'formContent' => 'required|string',
        ]);

        $tenant = $tenantContext->get();

        if ($this->editingId) {
            $item = KnowledgeItem::forTenant($tenant->id)->findOrFail($this->editingId);
            $service->update($item, [
                'title'     => $this->formTitle,
                'type'      => KnowledgeType::from($this->formType),
                'content'   => $this->formContent,
                'is_active' => $this->formActive,
            ]);
        } else {
            $service->create($tenant, [
                'title'   => $this->formTitle,
                'type'    => KnowledgeType::from($this->formType),
                'content' => $this->formContent,
            ]);
        }

        $this->showForm = false;
        session()->flash('success', 'Knowledge item saved.');
    }

    public function toggleActive(int $id, TenantContext $tenantContext, KnowledgeService $service): void
    {
        $item = KnowledgeItem::forTenant($tenantContext->getTenantId())->findOrFail($id);
        $service->toggle($item);
    }

    public function deleteItem(int $id, TenantContext $tenantContext): void
    {
        $item = KnowledgeItem::forTenant($tenantContext->getTenantId())->findOrFail($id);
        $item->delete();
    }

    public function approveCandidate(int $id, TenantContext $tenantContext, KnowledgeCandidateService $service): void
    {
        $candidate = KnowledgeCandidate::where('tenant_id', $tenantContext->getTenantId())->findOrFail($id);
        $service->approve($candidate, auth()->user());
    }

    public function rejectCandidate(int $id, TenantContext $tenantContext, KnowledgeCandidateService $service): void
    {
        $candidate = KnowledgeCandidate::where('tenant_id', $tenantContext->getTenantId())->findOrFail($id);
        $service->reject($candidate, auth()->user());
    }

    public function render(TenantContext $tenantContext)
    {
        $tenantId = $tenantContext->getTenantId();

        $itemsQuery = KnowledgeItem::forTenant($tenantId)->orderByDesc('created_at');
        if ($this->typeFilter !== '') {
            $itemsQuery->ofType(KnowledgeType::from($this->typeFilter));
        }

        return view('livewire.knowledge.knowledge-page', [
            'items'      => $itemsQuery->paginate(15),
            'candidates' => KnowledgeCandidate::where('tenant_id', $tenantId)
                ->whereNull('promoted_to_item_id')
                ->orderByDesc('created_at')
                ->paginate(15, pageName: 'cpage'),
            'types'      => KnowledgeType::cases(),
        ]);
    }
}
