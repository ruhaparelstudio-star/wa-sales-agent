<?php

namespace App\Modules\WhatsApp\Http\Livewire;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Exceptions\AgentSlotFullException;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use App\Modules\WhatsApp\Services\PairingService;
use Livewire\Component;

class QrPairingModal extends Component
{
    public ?string $pairingId        = null;
    public ?string $sseUrl           = null;
    public string  $status           = 'idle';
    public ?string $errorMessage     = null;
    public ?string $reconnectAgentId = null;

    public function mount(TenantContext $tenantContext, PairingService $pairingService): void
    {
        $this->initiatePairing($tenantContext, $pairingService);
    }

    public function initiatePairing(TenantContext $tenantContext, PairingService $pairingService): void
    {
        try {
            $tenant = $tenantContext->get();

            if ($this->reconnectAgentId !== null) {
                $agent   = WhatsAppAgent::forTenant($tenant->id)->findOrFail($this->reconnectAgentId);
                $pairing = $pairingService->attemptReconnect($tenant, $agent);
            } else {
                $pairing = $pairingService->initiatePairing($tenant);
            }
        } catch (AgentSlotFullException $e) {
            $this->pairingId = null;
            $this->sseUrl = null;
            $this->status = 'error';
            $this->errorMessage = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            $this->pairingId = null;
            $this->sseUrl = null;
            $this->status = 'error';
            $this->errorMessage = 'Failed to start pairing session. Please try again.';

            return;
        }

        $this->pairingId = $pairing->id;
        $this->sseUrl    = app(WhatsAppProviderInterface::class)->getQrStreamUrl($pairing->whatsapp_agent_id);
        $this->status    = 'waiting';
        $this->errorMessage = null;
    }

    public function closeModal(PairingService $pairingService): void
    {
        // If already connected, just close — pairing is complete, nothing to cancel
        if ($this->status !== 'connected' && $this->pairingId !== null) {
            $pairing = WhatsAppPairing::find($this->pairingId);
            if ($pairing) {
                $pairingService->cancelPairing($pairing);
            }
        }

        $this->dispatch('close-qr-modal');
    }

    public function onAgentConnected(): void
    {
        $this->status = 'connected';
        $this->dispatch('agent-connected');
    }

    public function render()
    {
        return view('livewire.whatsapp.qr-pairing-modal');
    }
}
