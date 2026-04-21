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

class SendOutboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $agentId,
        public readonly string $to,
        public readonly string $content,
        public readonly string $idempotencyKey,
        public readonly int $delaySeconds = 0,
    ) {}

    public function handle(OutboundDispatchService $dispatchService): void
    {
        if ($this->delaySeconds > 0) {
            sleep($this->delaySeconds);
        }

        $agent = WhatsAppAgent::find($this->agentId);
        if (! $agent) {
            Log::warning('[SendOutboundMessage] Agent not found', ['agent_id' => $this->agentId]);
            return;
        }

        $dispatchService->send($agent, $this->to, $this->content, $this->idempotencyKey);
    }
}
