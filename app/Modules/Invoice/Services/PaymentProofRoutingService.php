<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Log;

class PaymentProofRoutingService
{
    public function __construct(
        private readonly HandoffRequestService $handoffRequestService,
    ) {}

    /**
     * Route inbound media to invoice-specific handoff if there is a pending sent invoice.
     * Returns true if handled (invoice found), false otherwise.
     */
    public function route(Lead $lead, Message $message): bool
    {
        $invoice = ClientInvoice::forTenant($lead->tenant_id)
            ->forLead($lead->id)
            ->active()
            ->latest()
            ->first();

        if (! $invoice) {
            return false;
        }

        $conv = $message->conversation;

        $alreadyPending = $conv->handoffRequests()
            ->where('reason', HandoffReason::PaymentProof->value)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyPending) {
            Log::info('[PaymentProofRouting] Duplicate handoff skipped', ['lead_id' => $lead->id]);
            return true;
        }

        $mediaNote = $message->media_url
            ? " Media: {$message->media_url}"
            : '';

        $this->handoffRequestService->create(
            lead: $lead,
            conv: $conv,
            reason: HandoffReason::PaymentProof,
            detail: "Invoice {$invoice->invoice_number} — bukti bayar diterima.",
            summaryForAdmin: "Lead {$lead->phone_e164} mengirimkan bukti pembayaran untuk invoice {$invoice->invoice_number}.{$mediaNote} Harap verifikasi dan tandai lunas.",
        );

        Log::info('[PaymentProofRouting] Invoice-specific handoff created', [
            'invoice_id' => $invoice->id,
            'lead_id'    => $lead->id,
        ]);

        return true;
    }
}
