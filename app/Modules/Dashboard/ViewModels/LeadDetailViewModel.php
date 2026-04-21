<?php

namespace App\Modules\Dashboard\ViewModels;

use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;

class LeadDetailViewModel
{
    public function forLead(Lead $lead, Tenant $tenant): array
    {
        abort_if($lead->tenant_id !== $tenant->id, 404);

        $currentConversation = $lead->conversations()
            ->whereIn('status', [
                ConversationStatus::Active->value,
                ConversationStatus::Handoff->value,
            ])
            ->with(['summary'])
            ->latest()
            ->first();

        $pendingHandoffs = HandoffRequest::where('lead_id', $lead->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', HandoffStatus::Pending->value)
            ->orderByDesc('created_at')
            ->get();

        $invoices = ClientInvoice::where('lead_id', $lead->id)
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();

        return [
            'lead'                => $lead->load(['profile', 'memory', 'whatsappAgent']),
            'active_conversation' => $currentConversation,
            'pending_handoffs'    => $pendingHandoffs,
            'invoices'            => $invoices,
        ];
    }
}
