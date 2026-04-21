<?php

namespace App\Modules\Billing\Http\Livewire;

use App\Modules\Billing\Actions\UploadBillingProofAction;
use App\Modules\Billing\DTOs\UploadBillingProofDTO;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Billing')]
class BillingPage extends Component
{
    use WithFileUploads;
    use WithPagination;

    public ?int $uploadingInvoiceId = null;
    public $proofFile               = null;

    public function uploadProof(UploadBillingProofAction $action, TenantContext $tenantContext): void
    {
        $this->validate(['proofFile' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);

        $action->execute(
            new UploadBillingProofDTO($this->uploadingInvoiceId, $this->proofFile),
            $tenantContext->getTenantId(),
        );

        $this->reset(['uploadingInvoiceId', 'proofFile']);
        session()->flash('success', 'Payment proof uploaded.');
    }

    public function render(
        TenantContext $tenantContext,
        SubscriptionService $subscriptionService,
        AgentSlotPolicyService $slotPolicy,
    ) {
        $tenant   = $tenantContext->get();
        $sub      = $subscriptionService->getActiveSub($tenant);
        $invoices = BillingInvoice::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('livewire.billing.billing-page', [
            'subscription' => $sub,
            'plan'         => $sub?->plan,
            'slotsUsed'    => $slotPolicy->getUsedSlots($tenant),
            'invoices'     => $invoices,
        ]);
    }
}
