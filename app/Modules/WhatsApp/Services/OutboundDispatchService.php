<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Enums\MessageStatus;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationLockService;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Jobs\SendOutboundDocumentJob;
use App\Modules\WhatsApp\Jobs\SendOutboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OutboundDispatchService
{
    public function __construct(
        private readonly WhatsAppProviderInterface $provider,
        private readonly DuplicateMessageGuardService $duplicateGuard,
        private readonly ConversationLockService $conversationLockService,
    ) {}

    public function send(WhatsAppAgent $agent, string $to, string $content, string $idempotencyKey, bool $isFromAi = true): void
    {
        $content = trim($content);

        if ($content === '') {
            Log::warning('[OutboundDispatch] Empty outbound message skipped', [
                'agent_id' => $agent->id,
                'to' => $to,
            ]);
            return;
        }

        $to = $this->normalizeOutgoingRecipient($to);

        if (! $agent->isConnected()) {
            Log::warning('[OutboundDispatch] Agent not connected, dropping message', [
                'agent_id' => $agent->id,
                'to' => $to,
            ]);
            return;
        }

        $this->conversationLockService->blockForOutbound($idempotencyKey, function () use ($agent, $to, $content, $idempotencyKey, $isFromAi): void {
            $lead = $this->resolveLeadForRecipient($agent, $to);
            $conversation = $lead ? $this->resolveConversationForLead($lead) : null;

            $dispatch = $this->duplicateGuard->getOrCreateOutboundDispatch(
                agent: $agent,
                to: $to,
                messageType: MessageType::Text,
                idempotencyKey: $idempotencyKey,
                payload: ['content' => $content],
                lead: $lead,
                conversation: $conversation,
            );

            if ($this->duplicateGuard->shouldSkipOutbound($dispatch)) {
                Log::info('[OutboundDispatch] Duplicate outbound send skipped', [
                    'agent_id' => $agent->id,
                    'to' => $to,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return;
            }

            try {
                $result = $this->provider->sendMessage($agent->id, $to, $content, $idempotencyKey);

                Log::info('[OutboundDispatch] Message sent', [
                    'agent_id' => $agent->id,
                    'to' => $to,
                    'wa_message_id' => $result['message_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $message = $this->recordOutboundMessage(
                    agent: $agent,
                    to: $to,
                    content: $content,
                    messageType: MessageType::Text,
                    waMessageId: $result['message_id'] ?? null,
                    providerIdempotencyKey: $idempotencyKey,
                    status: MessageStatus::Sent,
                    isFromAi: $isFromAi,
                    existingLead: $lead,
                    existingConversation: $conversation,
                );

                $this->duplicateGuard->markOutboundSent($dispatch, $result['message_id'] ?? null, $message);
            } catch (\Throwable $error) {
                $this->duplicateGuard->markOutboundFailed($dispatch, $error);
                throw $error;
            }
        });
    }

    public function sendDocument(
        WhatsAppAgent $agent,
        string $to,
        string $filePath,
        string $filename,
        string $idempotencyKey,
        ?string $caption = null,
    ): void {
        $to = $this->normalizeOutgoingRecipient($to);

        if (! $agent->isConnected()) {
            Log::warning('[OutboundDispatch] Agent not connected, dropping document', [
                'agent_id' => $agent->id,
                'to' => $to,
                'filename' => $filename,
            ]);

            return;
        }

        if (! File::exists($filePath)) {
            throw new \RuntimeException("Document file not found: {$filePath}");
        }

        $this->conversationLockService->blockForOutbound($idempotencyKey, function () use ($agent, $to, $filePath, $filename, $idempotencyKey, $caption): void {
            $lead = $this->resolveLeadForRecipient($agent, $to);
            $conversation = $lead ? $this->resolveConversationForLead($lead) : null;

            $dispatch = $this->duplicateGuard->getOrCreateOutboundDispatch(
                agent: $agent,
                to: $to,
                messageType: MessageType::Document,
                idempotencyKey: $idempotencyKey,
                payload: [
                    'caption' => $caption,
                    'file_path' => $filePath,
                    'filename' => $filename,
                ],
                lead: $lead,
                conversation: $conversation,
            );

            if ($this->duplicateGuard->shouldSkipOutbound($dispatch)) {
                Log::info('[OutboundDispatch] Duplicate outbound document skipped', [
                    'agent_id' => $agent->id,
                    'to' => $to,
                    'filename' => $filename,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return;
            }

            try {
                $result = $this->provider->sendDocument($agent->id, $to, $filePath, $filename, $idempotencyKey, $caption);

                Log::info('[OutboundDispatch] Document sent', [
                    'agent_id' => $agent->id,
                    'to' => $to,
                    'filename' => $filename,
                    'wa_message_id' => $result['message_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $message = $this->recordOutboundMessage(
                    agent: $agent,
                    to: $to,
                    content: $caption,
                    messageType: MessageType::Document,
                    waMessageId: $result['message_id'] ?? null,
                    providerIdempotencyKey: $idempotencyKey,
                    status: MessageStatus::Sent,
                    isFromAi: true,
                    mediaFilename: $filename,
                    mediaMime: 'application/pdf',
                    mediaUrl: $filePath,
                    existingLead: $lead,
                    existingConversation: $conversation,
                );

                $this->duplicateGuard->markOutboundSent($dispatch, $result['message_id'] ?? null, $message);
            } catch (\Throwable $error) {
                $this->duplicateGuard->markOutboundFailed($dispatch, $error);
                throw $error;
            }
        });
    }

    public function queueSendDocument(
        WhatsAppAgent $agent,
        string $to,
        string $filePath,
        string $filename,
        string $idempotencyKey,
        ?string $caption = null,
        ?string $followUpText = null,
        int $followUpDelaySeconds = 0,
        string $queue = 'medium',
    ): void {
        SendOutboundDocumentJob::dispatch(
            agentId: $agent->id,
            to: $to,
            filePath: $filePath,
            filename: $filename,
            idempotencyKey: $idempotencyKey,
            caption: $caption,
            followUpText: $followUpText,
            followUpDelaySeconds: $followUpDelaySeconds,
        )->onQueue($queue);
    }

    public function queueSend(
        WhatsAppAgent $agent,
        string $to,
        string $content,
        string $queue = 'high',
        int $delaySeconds = 0,
        ?string $idempotencyKey = null,
    ): void {
        $content = trim($content);

        if ($content === '') {
            Log::warning('[OutboundDispatch] Empty outbound message not queued', [
                'agent_id' => $agent->id,
                'to' => $to,
                'queue' => $queue,
            ]);
            return;
        }

        if ($idempotencyKey === null) {
            Log::warning('[OutboundDispatch] queueSend invoked without idempotency key; falling back to UUID', [
                'agent_id' => $agent->id,
                'to' => $to,
                'queue' => $queue,
            ]);
            $idempotencyKey = (string) \Illuminate\Support\Str::uuid();
        }

        SendOutboundMessageJob::dispatch(
            agentId: $agent->id,
            to: $to,
            content: $content,
            idempotencyKey: $idempotencyKey,
            delaySeconds: $delaySeconds,
        )->onQueue($queue);
    }

    private function recordOutboundMessage(
        WhatsAppAgent $agent,
        string $to,
        ?string $content,
        MessageType $messageType,
        ?string $waMessageId,
        ?string $providerIdempotencyKey,
        MessageStatus $status,
        bool $isFromAi,
        ?string $mediaFilename = null,
        ?string $mediaMime = null,
        ?string $mediaUrl = null,
        ?Lead $existingLead = null,
        ?Conversation $existingConversation = null,
    ): ?Message {
        $lead = $existingLead ?? $this->resolveLeadForRecipient($agent, $to);

        if (! $lead) {
            Log::debug('[OutboundDispatch] Lead not found for outbound message', [
                'agent_id' => $agent->id,
                'to' => $to,
            ]);

            return null;
        }

        $conversation = $existingConversation ?? $this->resolveConversationForLead($lead);

        if (! $conversation) {
            Log::debug('[OutboundDispatch] Conversation not found for outbound message', [
                'lead_id' => $lead->id,
                'to' => $to,
            ]);

            return null;
        }

        $existing = $providerIdempotencyKey
            ? Message::query()
                ->where('direction', MessageDirection::Outbound->value)
                ->where('provider_idempotency_key', $providerIdempotencyKey)
                ->first()
            : null;

        if ($existing) {
            return $existing;
        }

        return Message::create([
            'tenant_id' => $lead->tenant_id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Outbound,
            'message_type' => $messageType,
            'content' => $content,
            'media_url' => $mediaUrl,
            'media_mime' => $mediaMime,
            'media_filename' => $mediaFilename,
            'wa_message_id' => $waMessageId,
            'provider_idempotency_key' => $providerIdempotencyKey,
            'status' => $status,
            'is_from_ai' => $isFromAi,
        ]);
    }

    private function resolveConversationForLead(Lead $lead): ?Conversation
    {
        return $lead->conversations()
            ->whereIn('status', ['active', 'handoff'])
            ->latest()
            ->first();
    }

    private function resolveLeadForRecipient(WhatsAppAgent $agent, string $to): ?Lead
    {
        $recipient = trim($to);
        $phone = $this->normalizeRecipientToPhone($recipient);

        return Lead::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where(function ($query) use ($recipient, $phone): void {
                $query->where('whatsapp_jid', $recipient);

                if ($phone !== null) {
                    $query->orWhere('phone_e164', $phone);
                }
            })
            ->latest('updated_at')
            ->first();
    }

    private function normalizeRecipientToPhone(string $recipient): ?string
    {
        $localPart = str_contains($recipient, '@')
            ? explode('@', $recipient, 2)[0]
            : $recipient;

        $digits = preg_replace('/\D+/', '', $localPart) ?? '';

        return $digits !== '' ? '+' . $digits : null;
    }

    private function normalizeOutgoingRecipient(string $recipient): string
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            return $recipient;
        }

        if (! str_contains($recipient, '@')) {
            return $recipient;
        }

        [$localPart, $domain] = explode('@', $recipient, 2);
        $digits = preg_replace('/\D+/', '', $localPart) ?? '';

        if ($digits === '' || trim($domain) === '') {
            return $recipient;
        }

        return "{$digits}@{$domain}";
    }
}
