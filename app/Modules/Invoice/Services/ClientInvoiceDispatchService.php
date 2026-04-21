<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Jobs\SendClientInvoiceJob;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\WhatsApp\Services\AgentRoutingService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ClientInvoiceDispatchService
{
    public function __construct(
        private readonly AgentRoutingService $agentRoutingService,
    ) {}

    public function dispatch(ClientInvoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException("Invoice {$invoice->id} is not in draft status.");
        }

        $lead   = $invoice->lead;
        $tenant = $invoice->tenant;

        $agent = $this->agentRoutingService->resolveAgentForLead(
            $tenant,
            $lead->whatsapp_agent_id ? (string) $lead->whatsapp_agent_id : null,
        );

        $invoice->update([
            'whatsapp_agent_id' => $agent->id,
            'status'            => ClientInvoiceStatus::Sent,
        ]);

        SendClientInvoiceJob::dispatch($invoice->id)->onQueue('medium');

        Log::info('[InvoiceDispatch] Job queued', [
            'invoice_id' => $invoice->id,
            'agent_id'   => $agent->id,
            'lead_id'    => $lead->id,
        ]);
    }

    public function handleDeliveryUpdate(string $waMessageId, string $status): void
    {
        $invoice = ClientInvoice::where('wa_message_id', $waMessageId)->first();

        if (! $invoice) {
            return;
        }

        $newStatus = match ($status) {
            'delivered' => ClientInvoiceStatus::Delivered,
            'read'      => ClientInvoiceStatus::Viewed,
            default     => null,
        };

        if ($newStatus && $invoice->status !== ClientInvoiceStatus::Paid) {
            $invoice->update(['status' => $newStatus]);

            if ($newStatus === ClientInvoiceStatus::Delivered) {
                $invoice->update(['delivered_at' => now()]);
            }

            Log::info('[InvoiceDispatch] Delivery status updated', [
                'invoice_id' => $invoice->id,
                'status'     => $newStatus->value,
            ]);
        }
    }
}
