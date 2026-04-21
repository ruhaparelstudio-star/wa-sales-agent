<?php

namespace App\Modules\WhatsApp\Http\Livewire;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\WhatsAppAgentService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('WhatsApp Agents')]
class AgentList extends Component
{
    public bool $showQrModal = false;
    public int $modalNonce = 0;
    public ?string $reconnectAgentId = null;

    public function setDefault(string $agentId, TenantContext $tenantContext, WhatsAppAgentService $agentService): void
    {
        $tenant = $tenantContext->get();
        $agent  = WhatsAppAgent::forTenant($tenant->id)->findOrFail($agentId);
        $agentService->setDefaultAgent($tenant, $agent);
    }

    public function disconnect(string $agentId, TenantContext $tenantContext, WhatsAppAgentService $agentService): void
    {
        $tenant = $tenantContext->get();
        WhatsAppAgent::forTenant($tenant->id)->findOrFail($agentId);
        $agentService->handleAgentDisconnected($agentId, 'manual_disconnect');
    }

    public function openQrModal(): void
    {
        $this->reconnectAgentId = null;
        $this->modalNonce++;
        $this->showQrModal = true;
        $this->dispatch('qr-modal-opened');
    }

    public function reconnectAgent(string $agentId): void
    {
        $this->reconnectAgentId = $agentId;
        $this->modalNonce++;
        $this->showQrModal = true;
        $this->dispatch('qr-modal-opened');
    }

    #[On('close-qr-modal')]
    public function closeQrModal(): void
    {
        $this->showQrModal = false;
    }

    public function render(TenantContext $tenantContext)
    {
        $agents = WhatsAppAgent::forTenant($tenantContext->getTenantId())
            ->orderByDesc('is_default')
            ->orderByDesc('last_connected_at')
            ->get();

        return view('livewire.whatsapp.agent-list', ['agents' => $agents]);
    }
}
