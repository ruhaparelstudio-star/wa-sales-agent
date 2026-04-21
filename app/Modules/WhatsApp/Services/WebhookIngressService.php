<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Invoice\Services\ClientInvoiceDispatchService;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Enums\MessageStatus;
use App\Modules\WhatsApp\Jobs\ProcessInboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookIngressService
{
    private const IDEMPOTENCY_TTL = 86400; // 24 hours

    private readonly ClientInvoiceDispatchService $invoiceDispatchService;

    public function __construct(
        private readonly WhatsAppAgentService $agentService,
        ?ClientInvoiceDispatchService $invoiceDispatchService = null,
    ) {
        $this->invoiceDispatchService = $invoiceDispatchService ?? app(ClientInvoiceDispatchService::class);
    }

    public function handle(array $payload, string $idempotencyKey): void
    {
        // Skip already-processed events
        $cacheKey = "webhook_idempotency:{$idempotencyKey}";
        if ($idempotencyKey !== '' && Cache::has($cacheKey)) {
            Log::debug('[WebhookIngress] Duplicate event skipped', ['key' => $idempotencyKey]);
            return;
        }

        $event = $payload['event'] ?? null;
        $agentId = $payload['agent_id'] ?? null;
        $data = $payload['data'] ?? [];

        if (! $event || ! $agentId) {
            Log::warning('[WebhookIngress] Malformed payload', $payload);
            return;
        }

        match ($event) {
            'message_received' => $this->handleMessageReceived($agentId, $data, $idempotencyKey),
            'agent_connected' => $this->handleAgentConnected($agentId, $data),
            'agent_disconnected' => $this->handleAgentDisconnected($agentId, $data),
            'message_status_update' => $this->handleMessageStatusUpdate($agentId, $data),
            default => Log::info('[WebhookIngress] Unknown event type', ['event' => $event]),
        };

        if ($idempotencyKey !== '') {
            Cache::put($cacheKey, true, self::IDEMPOTENCY_TTL);
        }
    }

    private function handleMessageReceived(string $agentId, array $data, string $webhookIdempotencyKey): void
    {
        if (($data['is_from_me'] ?? false) === true) {
            Log::debug('[WebhookIngress] Ignoring self-sent WhatsApp message', [
                'agent_id'      => $agentId,
                'wa_message_id' => $data['wa_message_id'] ?? null,
            ]);

            return;
        }

        ProcessInboundMessageJob::dispatch(
            agentId: $agentId,
            from: $data['from'] ?? '',
            fromJid: $data['from_jid'] ?? null,
            type: $data['type'] ?? 'text',
            content: $data['content'] ?? null,
            waMessageId: $data['wa_message_id'] ?? '',
            timestamp: $data['timestamp'] ?? now()->toISOString(),
            caption: $data['caption'] ?? null,
            mediaUrl: $data['media_url'] ?? null,
            mediaMime: $data['media_mime'] ?? null,
            quotedWaMessageId: $data['quoted_wa_message_id'] ?? null,
            quotedFromJid: $data['quoted_from_jid'] ?? null,
            quotedContent: $data['quoted_content'] ?? null,
            webhookIdempotencyKey: $webhookIdempotencyKey !== '' ? $webhookIdempotencyKey : null,
        )->onQueue('high');
    }

    private function handleAgentConnected(string $agentId, array $data): void
    {
        $this->agentService->handleAgentConnected($agentId, $data['phone_number'] ?? '');
    }

    private function handleAgentDisconnected(string $agentId, array $data): void
    {
        $this->agentService->handleAgentDisconnected($agentId, $data['reason'] ?? 'connection_lost');
    }

    private function handleMessageStatusUpdate(string $agentId, array $data): void
    {
        $waMessageId = $data['wa_message_id'] ?? null;
        $status      = $data['status'] ?? null;

        if (! $waMessageId || ! $status) {
            return;
        }

        $this->invoiceDispatchService->handleDeliveryUpdate($waMessageId, $status);

        $messageStatus = MessageStatus::tryFrom($status);

        if ($messageStatus) {
            Message::query()
                ->where('wa_message_id', $waMessageId)
                ->update(['status' => $messageStatus->value]);
        }

        Log::info('[WebhookIngress] Message status update', [
            'agent_id'      => $agentId,
            'wa_message_id' => $waMessageId,
            'status'        => $status,
        ]);
    }
}
