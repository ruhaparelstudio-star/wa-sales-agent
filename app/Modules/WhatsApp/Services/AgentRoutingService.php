<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use RuntimeException;

class AgentRoutingService
{
    public function resolveAgentForLead(Tenant $tenant, string $assignedAgentId = null): WhatsAppAgent
    {
        // Use assigned agent if it's connected
        if ($assignedAgentId) {
            $assigned = WhatsAppAgent::forTenant($tenant->id)
                ->connected()
                ->find($assignedAgentId);

            if ($assigned) {
                return $assigned;
            }
        }

        // Fallback: default connected agent
        $default = WhatsAppAgent::forTenant($tenant->id)
            ->connected()
            ->where('is_default', true)
            ->first();

        if ($default) {
            return $default;
        }

        // Last resort: any connected agent
        $any = WhatsAppAgent::forTenant($tenant->id)
            ->connected()
            ->first();

        if ($any) {
            return $any;
        }

        throw new RuntimeException("No connected WhatsApp agent available for tenant {$tenant->id}");
    }

    public function resolveAgentByPhone(string $agentPhone): ?WhatsAppAgent
    {
        $normalized = ltrim($agentPhone, '+');
        return WhatsAppAgent::where('phone_number', '+' . $normalized)
            ->where('status', AgentStatus::Connected->value)
            ->first();
    }
}
