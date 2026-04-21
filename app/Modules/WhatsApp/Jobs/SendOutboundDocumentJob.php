<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendOutboundDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $agentId,
        public readonly string $to,
        public readonly string $filePath,
        public readonly string $filename,
        public readonly string $idempotencyKey,
        public readonly ?string $caption = null,
        public readonly ?string $followUpText = null,
        public readonly int $followUpDelaySeconds = 0,
    ) {}

    public function handle(OutboundDispatchService $dispatchService): void
    {
        $agent = WhatsAppAgent::find($this->agentId);
        if (! $agent) {
            Log::warning('[SendOutboundDocument] Agent not found', ['agent_id' => $this->agentId]);
            return;
        }

        $dispatchService->sendDocument(
            agent: $agent,
            to: $this->to,
            filePath: $this->filePath,
            filename: $this->filename,
            idempotencyKey: $this->idempotencyKey,
            caption: $this->caption,
        );

        if ($this->followUpText !== null && $this->followUpText !== '') {
            if ($this->followUpDelaySeconds > 0) {
                sleep($this->followUpDelaySeconds);
            }

            $dispatchService->send(
                agent: $agent,
                to: $this->to,
                content: $this->followUpText,
                idempotencyKey: $this->idempotencyKey . ':follow-up',
            );
        }
    }
}
