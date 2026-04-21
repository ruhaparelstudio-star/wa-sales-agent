<?php

namespace App\Modules\Invoice\Jobs;

use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SendClientInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $invoiceId,
    ) {}

    public function handle(OutboundDispatchService $dispatchService): void
    {
        $invoice = ClientInvoice::with(['lead', 'tenant'])->find($this->invoiceId);

        if (! $invoice) {
            Log::warning('[SendClientInvoice] Invoice not found', ['invoice_id' => $this->invoiceId]);
            return;
        }

        $agent = WhatsAppAgent::find($invoice->whatsapp_agent_id);

        if (! $agent || ! $agent->isConnected()) {
            Log::warning('[SendClientInvoice] Agent unavailable', [
                'invoice_id' => $this->invoiceId,
                'agent_id'   => $invoice->whatsapp_agent_id,
            ]);
            $this->fail(new \RuntimeException('WhatsApp agent is not connected.'));
            return;
        }

        $to = $invoice->lead->preferredWhatsAppRecipient();

        try {
            // Send intro message first if present
            if ($invoice->intro_message) {
                $dispatchService->send(
                    agent: $agent,
                    to: $to,
                    content: $invoice->intro_message,
                    idempotencyKey: 'invoice-intro-' . $invoice->id,
                );
            }

            // Send PDF document
            $pdfPath  = $invoice->pdf_path
                ? Storage::path($invoice->pdf_path)
                : null;

            $filename = "Invoice_{$invoice->invoice_number}.pdf";

            $dispatchService->sendDocument(
                agent: $agent,
                to: $to,
                filePath: $pdfPath ?? '',
                filename: $filename,
                idempotencyKey: 'invoice-doc-' . $invoice->id,
            );

            $waMessageId = $invoice->lead
                ->messages()
                ->where('message_type', 'document')
                ->where('media_filename', $filename)
                ->latest('id')
                ->value('wa_message_id');

            $invoice->update([
                'wa_message_id' => $waMessageId,
                'sent_at'       => now(),
                'status'        => ClientInvoiceStatus::Sent,
            ]);

            Log::info('[SendClientInvoice] Invoice sent', [
                'invoice_id'   => $invoice->id,
                'wa_message_id'=> $waMessageId,
            ]);
        } catch (Throwable $e) {
            $invoice->update(['status' => ClientInvoiceStatus::Draft]);

            Log::error('[SendClientInvoice] Failed to send invoice', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
