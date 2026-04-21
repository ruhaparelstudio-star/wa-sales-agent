<?php

namespace App\Modules\Conversations\Services;

use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Collection;

class ConversationService
{
    public function openOrResume(Lead $lead, WhatsAppAgent $agent): Conversation
    {
        $existing = Conversation::where('lead_id', $lead->id)
            ->where('tenant_id', $lead->tenant_id)
            ->whereIn('status', [ConversationStatus::Active->value, ConversationStatus::Handoff->value])
            ->latest()
            ->first();

        if ($existing) {
            if ($existing->whatsapp_agent_id !== $agent->id) {
                $existing->update(['whatsapp_agent_id' => $agent->id]);
            }

            return $existing;
        }

        return Conversation::create([
            'tenant_id'          => $lead->tenant_id,
            'lead_id'            => $lead->id,
            'whatsapp_agent_id'  => $agent->id,
            'status'             => ConversationStatus::Active,
            'stage'              => ConversationStage::NewLead,
            'is_human_takeover'  => false,
        ]);
    }

    public function close(Conversation $conv): void
    {
        $conv->update([
            'status'    => ConversationStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function markHandoff(Conversation $conv): void
    {
        $conv->update([
            'status' => ConversationStatus::Handoff,
            'is_human_takeover' => true,
        ]);
    }

    public function markActive(Conversation $conv): void
    {
        $conv->update([
            'status'            => ConversationStatus::Active,
            'is_human_takeover' => false,
        ]);
    }

    public function getRecentMessages(Conversation $conv, int $limit = 6): Collection
    {
        return Message::where('conversation_id', $conv->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
