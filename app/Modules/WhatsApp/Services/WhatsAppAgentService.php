<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\Auth\Services\TenantMembershipService;
use App\Modules\Dashboard\Notifications\AgentDisconnectedNotification;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class WhatsAppAgentService
{
    public function getAgentsForTenant(Tenant $tenant): Collection
    {
        return WhatsAppAgent::forTenant($tenant->id)->orderByDesc('is_default')->get();
    }

    public function getDefaultAgent(Tenant $tenant): ?WhatsAppAgent
    {
        return WhatsAppAgent::forTenant($tenant->id)
            ->connected()
            ->where('is_default', true)
            ->first();
    }

    public function setDefaultAgent(Tenant $tenant, WhatsAppAgent $agent): void
    {
        // Only one default per tenant
        WhatsAppAgent::forTenant($tenant->id)->update(['is_default' => false]);
        $agent->update(['is_default' => true]);
    }

    public function handleAgentConnected(string $agentId, string $phoneNumber): void
    {
        DB::transaction(function () use ($agentId, $phoneNumber) {
            $agent = WhatsAppAgent::query()->lockForUpdate()->findOrFail($agentId);

            WhatsAppAgent::query()
                ->where('tenant_id', $agent->tenant_id)
                ->where('phone_number', $phoneNumber)
                ->where('id', '!=', $agent->id)
                ->update([
                    'status' => AgentStatus::Disconnected,
                    'phone_number' => null,
                    'is_default' => false,
                    'last_disconnected_at' => now(),
                ]);

            $agent->update([
                'status' => AgentStatus::Connected,
                'phone_number' => $phoneNumber,
                'last_connected_at' => now(),
            ]);

            // Mark the most recent pending pairing for this agent as completed
            WhatsAppPairing::where('whatsapp_agent_id', $agentId)
                ->pending()
                ->latest()
                ->first()
                ?->update([
                    'status' => PairingStatus::Completed,
                    'completed_at' => now(),
                ]);
        });
    }

    public function handleAgentDisconnected(string $agentId, string $reason): void
    {
        $agent = WhatsAppAgent::find($agentId);
        if (! $agent) {
            return;
        }

        $agent->update([
            'status' => AgentStatus::Disconnected,
            'last_disconnected_at' => now(),
        ]);

        // Notify tenant users about disconnection
        $this->dispatchDisconnectedNotification($agent, $reason);
    }

    private function dispatchDisconnectedNotification(WhatsAppAgent $agent, string $reason): void
    {
        $notification = new AgentDisconnectedNotification($agent, $reason);

        $agent->tenant
            ->tenantUsers()
            ->where('role', 'vendor_admin')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->each(fn ($admin) => $admin->notify($notification));
    }
}
