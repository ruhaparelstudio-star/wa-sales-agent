<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Dashboard\Notifications\AgentSlotLimitNotification;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Exceptions\AgentAlreadyConnectedException;
use App\Modules\WhatsApp\Exceptions\AgentSlotFullException;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PairingService
{
    public function __construct(
        private readonly WhatsAppProviderInterface $provider,
        private readonly AgentSlotPolicyService $slotPolicy,
        private readonly WhatsAppAgentService $agentService,
    ) {}

    public function initiatePairing(Tenant $tenant): WhatsAppPairing
    {
        if (! $this->slotPolicy->isSlotAvailable($tenant)) {
            $this->notifySlotLimit($tenant);
            throw new AgentSlotFullException();
        }

        // Create a new pending agent record (phone_number assigned after QR scan)
        $agent = WhatsAppAgent::create([
            'tenant_id' => $tenant->id,
            'status' => AgentStatus::Pending,
            'is_default' => false,
        ]);

        $pairing = WhatsAppPairing::create([
            'tenant_id' => $tenant->id,
            'whatsapp_agent_id' => $agent->id,
            'status' => PairingStatus::Pending,
            'pairing_token' => Str::random(64),
            'expires_at' => now()->addMinutes(10),
        ]);

        // Tell Baileys to start the socket and generate QR
        $this->provider->startAgent($agent->id);

        return $pairing->load('agent');
    }

    private function notifySlotLimit(Tenant $tenant): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $sub = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with('plan')
            ->first();

        $user->notify(new AgentSlotLimitNotification(
            planName: $sub?->plan?->name ?? 'Unknown',
            maxAgents: $sub?->plan?->max_agents ?? 0,
            currentConnected: $this->slotPolicy->getUsedSlots($tenant),
        ));
    }

    public function cancelPairing(WhatsAppPairing $pairing): void
    {
        if ($pairing->status !== PairingStatus::Pending) {
            return;
        }

        if ($pairing->whatsapp_agent_id) {
            $this->provider->cancelPairing($pairing->whatsapp_agent_id);

            WhatsAppAgent::where('id', $pairing->whatsapp_agent_id)
                ->where('status', AgentStatus::Pending)
                ->delete();
        }

        $pairing->update([
            'status' => PairingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function completePairing(string $agentId, string $phoneNumber): void
    {
        $this->agentService->handleAgentConnected($agentId, $phoneNumber);
    }

    public function attemptReconnect(Tenant $tenant, WhatsAppAgent $agent): WhatsAppPairing
    {
        if ($agent->status === AgentStatus::Connected) {
            throw new AgentAlreadyConnectedException();
        }

        if (! $this->slotPolicy->isSlotAvailable($tenant)) {
            throw new AgentSlotFullException();
        }

        $pairing = WhatsAppPairing::create([
            'tenant_id' => $tenant->id,
            'whatsapp_agent_id' => $agent->id,
            'status' => PairingStatus::Pending,
            'pairing_token' => Str::random(64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->provider->startAgent($agent->id);

        return $pairing->load('agent');
    }
}
