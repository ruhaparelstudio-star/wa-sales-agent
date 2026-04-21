<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Enums\InboundReceiptStatus;
use App\Modules\WhatsApp\Enums\OutboundDispatchStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppInboundReceipt;
use App\Modules\WhatsApp\Models\WhatsAppOutboundDispatch;
use Illuminate\Support\Arr;
use Throwable;

class DuplicateMessageGuardService
{
    public function startInbound(array $payload, ?string $webhookIdempotencyKey = null): WhatsAppInboundReceipt
    {
        $agentId = (string) ($payload['agent_id'] ?? '');
        $waMessageId = trim((string) ($payload['wa_message_id'] ?? ''));

        if ($agentId === '' || $waMessageId === '') {
            throw new \InvalidArgumentException('Inbound guard requires agent_id and wa_message_id.');
        }

        $attributes = [
            'whatsapp_agent_id' => $agentId,
            'wa_message_id' => $waMessageId,
        ];

        $createValues = [
            'from_phone' => $payload['from'] ?? null,
            'from_jid' => $payload['from_jid'] ?? null,
            'webhook_idempotency_key' => $webhookIdempotencyKey,
            'payload_hash' => $this->hashPayload($payload),
            'status' => InboundReceiptStatus::Processing,
            'quoted_wa_message_id' => $payload['quoted_wa_message_id'] ?? null,
            'quoted_from_jid' => $payload['quoted_from_jid'] ?? null,
            'quoted_content' => $payload['quoted_content'] ?? null,
            'failed_at' => null,
            'last_error' => null,
        ];

        $receipt = WhatsAppInboundReceipt::query()->firstOrCreate($attributes, $createValues);

        if (! $receipt->wasRecentlyCreated) {
            $receipt->fill(array_filter([
                'from_phone' => $payload['from'] ?? null,
                'from_jid' => $payload['from_jid'] ?? null,
                'webhook_idempotency_key' => $webhookIdempotencyKey,
                'payload_hash' => $this->hashPayload($payload),
                'quoted_wa_message_id' => $payload['quoted_wa_message_id'] ?? null,
                'quoted_from_jid' => $payload['quoted_from_jid'] ?? null,
                'quoted_content' => $payload['quoted_content'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
            if ($receipt->status === InboundReceiptStatus::Failed) {
                $receipt->status = InboundReceiptStatus::Processing;
                $receipt->failed_at = null;
                $receipt->last_error = null;
            }
            $receipt->save();
        }

        return $receipt->fresh(['message']);
    }

    public function shouldSkipInbound(WhatsAppInboundReceipt $receipt): bool
    {
        return in_array($receipt->status, [
            InboundReceiptStatus::Processed,
            InboundReceiptStatus::Ignored,
        ], true);
    }

    public function attachInboundMessage(WhatsAppInboundReceipt $receipt, Message $message): WhatsAppInboundReceipt
    {
        $receipt->update([
            'tenant_id' => $message->tenant_id,
            'lead_id' => $message->lead_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
        ]);

        return $receipt->fresh(['message']);
    }

    public function markAgentCoreDispatched(WhatsAppInboundReceipt $receipt): void
    {
        if ($receipt->agent_core_dispatched_at !== null) {
            return;
        }

        $receipt->update(['agent_core_dispatched_at' => now()]);
    }

    public function markInboundProcessed(WhatsAppInboundReceipt $receipt, Message $message): void
    {
        $receipt->update([
            'tenant_id' => $message->tenant_id,
            'lead_id' => $message->lead_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'status' => InboundReceiptStatus::Processed,
            'processed_at' => now(),
            'failed_at' => null,
            'last_error' => null,
        ]);
    }

    public function markInboundIgnored(WhatsAppInboundReceipt $receipt, ?string $reason = null): void
    {
        $receipt->update([
            'status' => InboundReceiptStatus::Ignored,
            'processed_at' => now(),
            'last_error' => $reason,
        ]);
    }

    public function markInboundFailed(WhatsAppInboundReceipt $receipt, Throwable $error): void
    {
        $receipt->update([
            'status' => InboundReceiptStatus::Failed,
            'failed_at' => now(),
            'last_error' => $error->getMessage(),
        ]);
    }

    public function getOrCreateOutboundDispatch(
        WhatsAppAgent $agent,
        string $to,
        MessageType $messageType,
        string $idempotencyKey,
        array $payload = [],
        ?Lead $lead = null,
        ?Conversation $conversation = null,
    ): WhatsAppOutboundDispatch {
        $dispatch = WhatsAppOutboundDispatch::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'tenant_id' => $agent->tenant_id,
                'whatsapp_agent_id' => $agent->id,
                'lead_id' => $lead?->id,
                'conversation_id' => $conversation?->id,
                'recipient' => $to,
                'message_type' => $messageType->value,
                'payload_hash' => $this->hashPayload($payload),
                'status' => OutboundDispatchStatus::Pending,
            ],
        );

        if (! $dispatch->wasRecentlyCreated) {
            $dispatch->fill([
                'tenant_id' => $dispatch->tenant_id ?? $agent->tenant_id,
                'lead_id' => $dispatch->lead_id ?? $lead?->id,
                'conversation_id' => $dispatch->conversation_id ?? $conversation?->id,
                'recipient' => $dispatch->recipient ?: $to,
                'payload_hash' => $dispatch->payload_hash ?: $this->hashPayload($payload),
            ]);
            $dispatch->save();
        }

        return $dispatch->fresh();
    }

    public function shouldSkipOutbound(WhatsAppOutboundDispatch $dispatch): bool
    {
        return $dispatch->status === OutboundDispatchStatus::Sent;
    }

    public function markOutboundSent(
        WhatsAppOutboundDispatch $dispatch,
        ?string $providerMessageId,
        ?Message $message = null,
    ): void {
        $dispatch->update([
            'message_id' => $message?->id ?? $dispatch->message_id,
            'provider_message_id' => $providerMessageId,
            'status' => OutboundDispatchStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markOutboundFailed(WhatsAppOutboundDispatch $dispatch, Throwable $error): void
    {
        $dispatch->update([
            'status' => OutboundDispatchStatus::Failed,
            'last_error' => $error->getMessage(),
        ]);
    }

    private function hashPayload(array $payload): string
    {
        $normalized = Arr::sortRecursive($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
