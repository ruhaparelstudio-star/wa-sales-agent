<?php

use App\Modules\AgentCore\DTOs\BookingFieldCandidate;
use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\SharedConversationContext;
use App\Modules\AgentCore\DTOs\TurnOutcome;
use App\Modules\AgentCore\Enums\FieldCandidateStatus;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Enums\ResponseMode;
use App\Modules\AgentCore\Enums\TurnOutcomeType;

test('final turn decision serializes to the expected contract shape', function () {
    $dto = new FinalTurnDecision(
        turnId: 'uuid-or-internal-id',
        conversationId: 'uuid',
        leadId: 'uuid-or-null',
        decisionSource: [
            'rules_used' => true,
            'classifier_used' => true,
            'guards_used' => true,
            'stage_engine_used' => true,
            'final_authority' => 'turn_decision_service',
        ],
        detectedSignals: [
            'rule_intent' => 'ask_package_details',
            'classifier_intent' => 'ask_package_details',
            'sentiment' => 'neutral',
            'handoff_requested' => false,
            'payment_topic' => false,
            'pricing_focus' => false,
            'booking_signal' => false,
            'active_topic' => 'package_discussion',
        ],
        finalDecision: [
            'intent' => 'ask_package_details',
            'stage_before' => 'qualification',
            'stage_after' => 'package_discussion',
            'action' => FinalAction::ReplyWithPackageDetails,
            'response_mode' => ResponseMode::BusinessPayloadToResponder,
            'fallback_reason' => null,
            'requires_handoff' => false,
            'requires_tool_calls' => ['get_package_catalog'],
            'requires_confirmation' => false,
            'should_reply' => true,
            'should_store_memory' => true,
        ],
        missingFields: [],
        fieldUpdates: [],
        conflicts: [],
        notes: [
            'Rules and classifier aligned',
            'No stage conflict detected',
        ],
    );

    $expected = [
        'schema_version' => '1.0',
        'turn_id' => 'uuid-or-internal-id',
        'conversation_id' => 'uuid',
        'lead_id' => 'uuid-or-null',
        'decision_source' => [
            'rules_used' => true,
            'classifier_used' => true,
            'guards_used' => true,
            'stage_engine_used' => true,
            'final_authority' => 'turn_decision_service',
        ],
        'detected_signals' => [
            'rule_intent' => 'ask_package_details',
            'classifier_intent' => 'ask_package_details',
            'sentiment' => 'neutral',
            'handoff_requested' => false,
            'payment_topic' => false,
            'pricing_focus' => false,
            'booking_signal' => false,
            'active_topic' => 'package_discussion',
        ],
        'final_decision' => [
            'intent' => 'ask_package_details',
            'stage_before' => 'qualification',
            'stage_after' => 'package_discussion',
            'action' => 'reply_with_package_details',
            'response_mode' => 'business_payload_to_responder',
            'fallback_reason' => null,
            'requires_handoff' => false,
            'requires_tool_calls' => ['get_package_catalog'],
            'requires_confirmation' => false,
            'should_reply' => true,
            'should_store_memory' => true,
        ],
        'missing_fields' => [],
        'field_updates' => [],
        'conflicts' => [],
        'notes' => [
            'Rules and classifier aligned',
            'No stage conflict detected',
        ],
    ];

    expect($dto->toArray())->toBe($expected)
        ->and(json_decode($dto->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});

test('business response payload serializes to the expected contract shape', function () {
    $dto = new BusinessResponsePayload(
        payloadType: 'package_details',
        action: FinalAction::ReplyWithPackageDetails,
        data: [
            'package_code' => 'PREWED_BASIC',
            'package_name' => 'Prewedding Basic',
            'highlights' => [
                '1 fotografer',
                '50 edited photos',
                'softcopy included',
            ],
            'limitations' => [],
            'cta' => [
                'type' => 'offer_comparison',
                'label' => 'Tawarkan perbandingan paket lain',
            ],
        ],
        responseRules: [
            'tone' => 'warm_helpful',
            'must_answer_latest_question_first' => true,
            'must_not_invent_price' => true,
            'must_not_invent_availability' => true,
            'must_not_promise_followup_without_action' => true,
        ],
    );

    $expected = [
        'schema_version' => '1.0',
        'payload_type' => 'package_details',
        'action' => 'reply_with_package_details',
        'data' => [
            'package_code' => 'PREWED_BASIC',
            'package_name' => 'Prewedding Basic',
            'highlights' => [
                '1 fotografer',
                '50 edited photos',
                'softcopy included',
            ],
            'limitations' => [],
            'cta' => [
                'type' => 'offer_comparison',
                'label' => 'Tawarkan perbandingan paket lain',
            ],
        ],
        'response_rules' => [
            'tone' => 'warm_helpful',
            'must_answer_latest_question_first' => true,
            'must_not_invent_price' => true,
            'must_not_invent_availability' => true,
            'must_not_promise_followup_without_action' => true,
        ],
    ];

    expect($dto->toArray())->toBe($expected)
        ->and(json_decode($dto->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});

test('shared conversation context serializes to the expected contract shape', function () {
    $dto = new SharedConversationContext(
        conversationId: 'uuid',
        activeTopic: 'package_discussion',
        currentStage: 'package_discussion',
        stageGoal: 'Explain package contents clearly and guide to comparison or booking interest',
        latestUserAsk: 'Paketnya isinya apa aja ka?',
        recentSummary: 'User previously asked pricelist, then shifted to asking package contents.',
        filledSlots: [
            'event_date' => '2026-04-18',
            'location' => null,
            'package_interest' => 'prewedding',
        ],
        unresolvedQuestions: [
            'Package contents detail',
        ],
        askedFields: [
            'event_date',
        ],
        nextExpectedField: null,
        nextBestAction: 'reply_with_package_details',
        memoryFacts: [
            'User has shown interest in prewedding package',
        ],
        recentMessages: [
            [
                'role' => 'user',
                'text' => 'Sebelum itu boleh tau pricelistnya dulu nggak kak',
            ],
            [
                'role' => 'assistant',
                'text' => 'Boleh kak, ini pricelistnya...',
            ],
            [
                'role' => 'user',
                'text' => 'Paketnya isinya apa aja ka?',
            ],
        ],
    );

    $expected = [
        'schema_version' => '1.0',
        'conversation_id' => 'uuid',
        'active_topic' => 'package_discussion',
        'current_stage' => 'package_discussion',
        'stage_goal' => 'Explain package contents clearly and guide to comparison or booking interest',
        'latest_user_ask' => 'Paketnya isinya apa aja ka?',
        'recent_summary' => 'User previously asked pricelist, then shifted to asking package contents.',
        'filled_slots' => [
            'event_date' => '2026-04-18',
            'location' => null,
            'package_interest' => 'prewedding',
        ],
        'unresolved_questions' => [
            'Package contents detail',
        ],
        'asked_fields' => [
            'event_date',
        ],
        'next_expected_field' => null,
        'next_best_action' => 'reply_with_package_details',
        'memory_facts' => [
            'User has shown interest in prewedding package',
        ],
        'recent_messages' => [
            [
                'role' => 'user',
                'text' => 'Sebelum itu boleh tau pricelistnya dulu nggak kak',
            ],
            [
                'role' => 'assistant',
                'text' => 'Boleh kak, ini pricelistnya...',
            ],
            [
                'role' => 'user',
                'text' => 'Paketnya isinya apa aja ka?',
            ],
        ],
    ];

    expect($dto->toArray())->toBe($expected)
        ->and(json_decode($dto->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});

test('booking field candidate serializes to the expected contract shape', function () {
    $dto = new BookingFieldCandidate(
        fieldName: 'event_date',
        rawValue: '18 April 2026',
        normalizedValue: '2026-04-18',
        confidence: 0.84,
        source: 'llm',
        status: FieldCandidateStatus::Candidate,
        requiresConfirmation: true,
        validation: [
            'is_valid_format' => true,
            'is_future_date' => true,
            'has_ambiguity' => false,
        ],
    );

    $expected = [
        'schema_version' => '1.0',
        'field_name' => 'event_date',
        'raw_value' => '18 April 2026',
        'normalized_value' => '2026-04-18',
        'confidence' => 0.84,
        'source' => 'llm',
        'status' => 'candidate',
        'requires_confirmation' => true,
        'validation' => [
            'is_valid_format' => true,
            'is_future_date' => true,
            'has_ambiguity' => false,
        ],
    ];

    expect($dto->toArray())->toBe($expected)
        ->and(json_decode($dto->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});

test('turn outcome serializes to the expected contract shape', function () {
    $dto = new TurnOutcome(
        turnId: 'uuid',
        conversationId: 'uuid',
        decisionIntent: 'ask_package_details',
        decisionAction: FinalAction::ReplyWithPackageDetails,
        outcome: TurnOutcomeType::Replied,
        outbound: [
            'dispatch_attempted' => true,
            'dispatch_success' => true,
            'channel' => 'whatsapp',
            'reason_if_not_sent' => null,
        ],
        fallback: [
            'used' => false,
            'reason' => null,
        ],
        noReplyReason: null,
        timing: [
            'classifier_ms' => 120,
            'decision_ms' => 10,
            'generation_ms' => 620,
            'dispatch_ms' => 90,
        ],
    );

    $expected = [
        'schema_version' => '1.0',
        'turn_id' => 'uuid',
        'conversation_id' => 'uuid',
        'decision_intent' => 'ask_package_details',
        'decision_action' => 'reply_with_package_details',
        'outcome' => 'replied',
        'outbound' => [
            'dispatch_attempted' => true,
            'dispatch_success' => true,
            'channel' => 'whatsapp',
            'reason_if_not_sent' => null,
        ],
        'fallback' => [
            'used' => false,
            'reason' => null,
        ],
        'no_reply_reason' => null,
        'timing' => [
            'classifier_ms' => 120,
            'decision_ms' => 10,
            'generation_ms' => 620,
            'dispatch_ms' => 90,
        ],
    ];

    expect($dto->toArray())->toBe($expected)
        ->and(json_decode($dto->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});
