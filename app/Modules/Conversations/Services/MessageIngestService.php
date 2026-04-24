<?php

namespace App\Modules\Conversations\Services;

use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Enums\MessageStatus;
use App\Modules\Conversations\Enums\MessageType;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Services\LeadService;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\Log;

class MessageIngestService
{
    public function __construct(
        private readonly LeadService         $leadService,
        private readonly ConversationService $conversationService,
    ) {}

    public function ingestInbound(array $webhookData): Message
    {
        $agentId       = $webhookData['agent_id'];
        $phone         = $webhookData['from'];
        $fromJid       = $webhookData['from_jid'] ?? null;
        $typeRaw       = $webhookData['type'] ?? 'text';
        $content       = $webhookData['content'] ?? null;
        $waMessageId   = $webhookData['wa_message_id'] ?? null;
        $mediaUrl      = $webhookData['media_url'] ?? null;
        $mediaMime     = $webhookData['media_mime'] ?? null;
        $mediaFilename = $webhookData['media_filename'] ?? null;
        $providerIdempotencyKey = $webhookData['provider_idempotency_key'] ?? null;
        $quotedWaMessageId = $webhookData['quoted_wa_message_id'] ?? null;
        $quotedFromJid = $webhookData['quoted_from_jid'] ?? null;
        $quotedContent = $webhookData['quoted_content'] ?? null;

        // Resolve agent → tenant
        $agent  = WhatsAppAgent::findOrFail($agentId);
        $tenant = $agent->tenant;

        // Resolve / create lead
        $lead = $this->leadService->findOrCreateByPhone($tenant, $phone, $agent, $fromJid);

        // Open or resume conversation
        $conversation = $this->conversationService->openOrResume($lead, $agent);

        // Map message type
        $messageType = MessageType::tryFrom($typeRaw) ?? MessageType::Text;

        $existingMessage = null;
        if ($waMessageId) {
            $existingMessage = Message::query()
                ->where('direction', MessageDirection::Inbound->value)
                ->where('lead_id', $lead->id)
                ->where('wa_message_id', $waMessageId)
                ->first();
        }

        if ($existingMessage) {
            $updates = array_filter([
                'provider_idempotency_key' => $existingMessage->provider_idempotency_key ?: $providerIdempotencyKey,
                'quoted_wa_message_id' => $existingMessage->quoted_wa_message_id ?: $quotedWaMessageId,
                'quoted_from_jid' => $existingMessage->quoted_from_jid ?: $quotedFromJid,
                'quoted_content' => $existingMessage->quoted_content ?: $quotedContent,
            ], static fn (mixed $value): bool => $value !== null);

            if ($updates !== []) {
                $existingMessage->update($updates);
            }

            Log::info('[MessageIngest] Duplicate inbound message reused', [
                'tenant_id' => $tenant->id,
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'wa_message_id' => $waMessageId,
                'message_id' => $existingMessage->id,
            ]);

            return $existingMessage->fresh();
        }

        // Persist message
        $message = Message::create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conversation->id,
            'lead_id'         => $lead->id,
            'direction'       => MessageDirection::Inbound,
            'message_type'    => $messageType,
            'content'         => $content,
            'media_url'       => $mediaUrl,
            'media_mime'      => $mediaMime,
            'media_filename'  => $mediaFilename,
            'wa_message_id'   => $waMessageId,
            'provider_idempotency_key' => $providerIdempotencyKey,
            'quoted_wa_message_id' => $quotedWaMessageId,
            'quoted_from_jid' => $quotedFromJid,
            'quoted_content' => $quotedContent,
            'status'          => MessageStatus::Delivered,
            'is_from_ai'      => false,
        ]);

        // Touch lead timestamp
        $lead->update(['last_message_at' => now()]);

        Log::info('[MessageIngest] Inbound message ingested', [
            'tenant_id'       => $tenant->id,
            'lead_id'         => $lead->id,
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'type'            => $messageType->value,
        ]);

        return $message;
    }

    public function isMediaMessage(Message $message): bool
    {
        return $message->message_type->isMedia();
    }
}
