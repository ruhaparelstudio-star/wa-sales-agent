<?php

namespace App\Modules\Conversations\Actions;

use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Invoice\Services\PaymentProofRoutingService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

class PaymentProofDetectedAction
{
    public function __construct(
        private readonly HandoffRequestService      $handoffRequestService,
        private readonly PaymentProofRoutingService $paymentProofRoutingService,
    ) {}

    public function run(Message $message, Tenant $tenant): string
    {
        $lead = $message->lead;
        $conv = $message->conversation;

        // Invoice-aware routing: if a sent invoice exists, create an invoice-specific handoff
        if ($this->paymentProofRoutingService->route($lead, $message)) {
            return 'Bukti transfer sudah kami terima! Tim kami akan verifikasi segera.';
        }

        // Generic fallback: no invoice found, still create a handoff for hot leads
        $hasPendingHandoff = $conv->handoffRequests()
            ->where('reason', HandoffReason::PaymentProof->value)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingHandoff) {
            Log::info('[PaymentProof] Duplicate handoff skipped', ['lead_id' => $lead->id]);
            return 'Bukti transfer sudah kami terima! Tim kami akan verifikasi segera.';
        }

        $this->handoffRequestService->create(
            lead: $lead,
            conv: $conv,
            reason: HandoffReason::PaymentProof,
            detail: 'Inbound media detected from hot lead — possible payment proof.',
            summaryForAdmin: "Lead {$lead->phone_e164} mengirimkan bukti transfer. Harap verifikasi.",
        );

        Log::info('[PaymentProof] Handoff created', [
            'tenant_id'  => $tenant->id,
            'lead_id'    => $lead->id,
            'message_id' => $message->id,
        ]);

        return 'Bukti transfer sudah kami terima! Tim kami akan verifikasi segera.';
    }
}
