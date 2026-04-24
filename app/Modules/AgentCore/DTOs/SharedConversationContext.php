<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;

final class SharedConversationContext
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $filledSlots
     * @param  list<string>  $unresolvedQuestions
     * @param  list<string>  $askedFields
     * @param  list<string>  $memoryFacts
     * @param  list<array{role: string, text: string}>  $recentMessages
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly ?string $activeTopic,
        public readonly ?string $currentStage,
        public readonly ?string $stageGoal,
        public readonly ?string $latestUserAsk,
        public readonly ?string $recentSummary,
        public readonly array $filledSlots = [],
        public readonly array $unresolvedQuestions = [],
        public readonly array $askedFields = [],
        public readonly ?string $nextExpectedField = null,
        public readonly ?string $nextBestAction = null,
        public readonly array $memoryFacts = [],
        public readonly array $recentMessages = [],
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     conversation_id: string,
     *     active_topic: ?string,
     *     current_stage: ?string,
     *     stage_goal: ?string,
     *     latest_user_ask: ?string,
     *     recent_summary: ?string,
     *     filled_slots: array<string, mixed>,
     *     unresolved_questions: list<string>,
     *     asked_fields: list<string>,
     *     next_expected_field: ?string,
     *     next_best_action: ?string,
     *     memory_facts: list<string>,
     *     recent_messages: list<array{role: string, text: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'conversation_id' => $this->conversationId,
            'active_topic' => $this->activeTopic,
            'current_stage' => $this->currentStage,
            'stage_goal' => $this->stageGoal,
            'latest_user_ask' => $this->latestUserAsk,
            'recent_summary' => $this->recentSummary,
            'filled_slots' => $this->normalizeValue($this->filledSlots),
            'unresolved_questions' => array_values($this->unresolvedQuestions),
            'asked_fields' => array_values($this->askedFields),
            'next_expected_field' => $this->nextExpectedField,
            'next_best_action' => $this->nextBestAction,
            'memory_facts' => array_values($this->memoryFacts),
            'recent_messages' => array_values(array_map(function (array $message): array {
                return [
                    'role' => (string) ($message['role'] ?? ''),
                    'text' => (string) ($message['text'] ?? ''),
                ];
            }, $this->recentMessages)),
        ];
    }
}
