<?php

namespace App\Modules\Conversations\Actions;

use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationStageTransition;
use Illuminate\Support\Facades\DB;

class TransitionConversationStageAction
{
    /**
     * Apply a stage transition on the given conversation. Returns the effective stage
     * after the call (current stage if transition is invalid).
     */
    public function execute(
        Conversation $conversation,
        ConversationStage $to,
        string $triggeredBy = 'llm',
        ?string $reason = null,
    ): ConversationStage {
        $from = $conversation->stageEnum();

        if ($from === $to) {
            return $from;
        }

        $allowed = $from->canTransitionTo($to);

        return DB::transaction(function () use ($conversation, $from, $to, $triggeredBy, $reason, $allowed) {
            ConversationStageTransition::create([
                'tenant_id'       => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'from_stage'      => $from->value,
                'to_stage'        => $allowed ? $to->value : $from->value,
                'triggered_by'    => $triggeredBy,
                'reason'          => $allowed ? $reason : 'invalid_transition:' . $to->value,
                'created_at'      => now(),
            ]);

            if (! $allowed) {
                return $from;
            }

            $conversation->forceFill([
                'stage'                  => $to->value,
                'stage_updated_at'       => now(),
                'stage_transition_count' => ($conversation->stage_transition_count ?? 0) + 1,
            ])->save();

            return $to;
        });
    }
}
