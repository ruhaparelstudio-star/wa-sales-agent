<?php

namespace App\Modules\Leads\Http\Livewire;

use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Dashboard\ViewModels\LeadDetailViewModel;
use App\Modules\Invoice\Actions\CreateClientInvoiceAction;
use App\Modules\Invoice\Actions\SendClientInvoiceAction;
use App\Modules\Invoice\Actions\UploadClientInvoiceAction;
use App\Modules\Invoice\DTOs\CreateClientInvoiceDTO;
use App\Modules\Invoice\DTOs\UploadClientInvoiceDTO;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Lead Detail')]
class LeadDetail extends Component
{
    use WithFileUploads;
    use WithPagination;

    public int    $leadId;
    public string $activeTab    = 'profile';
    public string $replyMessage = '';

    // Invoice form state
    public array  $lineItems  = [];
    public string $dueDate    = '';
    public $invoiceFile       = null;

    public function mount(int $leadId): void
    {
        $this->leadId = $leadId;
    }

    public function takeover(int $conversationId, TenantContext $tenantContext, ConversationService $conversationService): void
    {
        $tenantId = $tenantContext->getTenantId();
        $conv     = \App\Modules\Conversations\Models\Conversation::where('tenant_id', $tenantId)->findOrFail($conversationId);
        $conv->update(['is_human_takeover' => true]);
        $conversationService->markHandoff($conv);
    }

    public function sendReply(TenantContext $tenantContext, OutboundDispatchService $dispatcher): void
    {
        $message = trim($this->replyMessage);

        if ($message === '') {
            return;
        }

        $tenantId     = $tenantContext->getTenantId();
        $lead         = Lead::forTenant($tenantId)->with('whatsappAgent')->findOrFail($this->leadId);
        $conversation = $lead->conversations()
            ->whereIn('status', ['active', 'handoff'])
            ->latest()
            ->first();

        if (! $conversation || ! $lead->whatsappAgent) {
            session()->flash('error', 'Tidak ada percakapan aktif atau agent tidak tersambung.');
            return;
        }

        if (! $conversation->is_human_takeover) {
            $conversation->update(['is_human_takeover' => true]);
        }

        $dispatcher->send(
            agent: $lead->whatsappAgent,
            to: $lead->whatsapp_jid ?? $lead->phone_e164,
            content: $message,
            idempotencyKey: (string) Str::uuid(),
            isFromAi: false,
        );

        $this->replyMessage = '';
    }

    public function resolveHandoff(int $handoffId, TenantContext $tenantContext, HandoffRequestService $handoffService): void
    {
        $tenantId = $tenantContext->getTenantId();
        $handoff  = HandoffRequest::where('tenant_id', $tenantId)->findOrFail($handoffId);
        $handoffService->resolve($handoff, auth()->user());
    }

    public function createInvoice(TenantContext $tenantContext, CreateClientInvoiceAction $action): void
    {
        $this->validate([
            'lineItems'                  => 'required|array|min:1',
            'lineItems.*.description'    => 'required|string',
            'lineItems.*.amount'         => 'required|numeric|min:0',
            'dueDate'                    => 'required|date',
        ]);

        $tenant = $tenantContext->get();
        $lead   = Lead::forTenant($tenant->id)->findOrFail($this->leadId);

        // Map lineItems to the format expected by the service
        $items = array_map(fn ($item, $i) => [
            'description' => $item['description'],
            'quantity'    => 1,
            'unit_price'  => (float) $item['amount'],
            'sort_order'  => $i,
        ], $this->lineItems, array_keys($this->lineItems));

        $action->execute(
            new CreateClientInvoiceDTO(
                leadId:       $lead->id,
                items:        $items,
                dueDate:      $this->dueDate,
                introMessage: null,
                notes:        null,
            ),
            $tenant,
            auth()->user(),
        );

        $this->reset(['lineItems', 'dueDate']);
        session()->flash('success', 'Invoice created.');
    }

    public function uploadInvoice(TenantContext $tenantContext, UploadClientInvoiceAction $action): void
    {
        $this->validate(['invoiceFile' => 'required|file|mimes:pdf|max:10240']);

        $tenant = $tenantContext->get();
        $lead   = Lead::forTenant($tenant->id)->findOrFail($this->leadId);

        $action->execute(
            new UploadClientInvoiceDTO(
                leadId:       $lead->id,
                file:         $this->invoiceFile,
                dueDate:      null,
                introMessage: null,
            ),
            $tenant,
            auth()->user(),
        );

        $this->reset('invoiceFile');
        session()->flash('success', 'Invoice uploaded.');
    }

    public function sendInvoice(int $invoiceId, TenantContext $tenantContext, SendClientInvoiceAction $action): void
    {
        $tenant = $tenantContext->get();
        $action->execute($invoiceId, $tenant);
        session()->flash('success', 'Invoice sent.');
    }

    public function render(TenantContext $tenantContext, LeadDetailViewModel $viewModel)
    {
        $tenant = $tenantContext->get();
        $lead   = Lead::forTenant($tenant->id)->findOrFail($this->leadId);
        $data   = $viewModel->forLead($lead, $tenant);

        $messages = collect();
        if ($data['active_conversation']) {
            $messages = $data['active_conversation']->messages()
                ->orderBy('created_at')
                ->paginate(30, pageName: 'msg');
        }

        return view('livewire.leads.lead-detail', array_merge($data, ['messages' => $messages]));
    }
}
