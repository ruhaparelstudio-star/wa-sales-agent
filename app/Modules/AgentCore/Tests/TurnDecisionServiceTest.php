<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\DTOs\SharedConversationContext;
use App\Modules\AgentCore\DTOs\TurnDecisionInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Services\TurnDecisionService;
use App\Modules\Conversations\Enums\ConversationStage;

function makeSharedDecisionContext(?string $stage = 'qualification'): SharedConversationContext
{
    return new SharedConversationContext(
        conversationId: 'conversation-1',
        activeTopic: $stage,
        currentStage: $stage,
        stageGoal: 'Keep the conversation moving without repeating answered questions.',
        latestUserAsk: 'Boleh jelasin detail paketnya?',
        recentSummary: 'User is actively evaluating the offer.',
        filledSlots: [
            'event_date' => '2026-12-20',
        ],
        unresolvedQuestions: [],
        askedFields: ['event_date'],
        nextExpectedField: null,
        nextBestAction: 'respond_to_user',
        memoryFacts: ['event_date: 2026-12-20'],
        recentMessages: [
            ['role' => 'user', 'text' => 'Halo kak'],
            ['role' => 'assistant', 'text' => 'Halo, ada yang bisa dibantu?'],
        ],
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeTurnDecisionInput(array $overrides = []): TurnDecisionInput
{
    $ruleInterpretation = $overrides['ruleInterpretation'] ?? new InterpretationResult(
        canonicalIntent: 'package_inquiry',
        legacyIntent: 'tanya_paket',
        slots: [],
        confidence: 0.92,
        source: 'rules',
    );

    $classifierResult = $overrides['classifierResult'] ?? new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::PackageRecommendation,
        missingCriticalFields: [],
    );

    return new TurnDecisionInput(
        turnId: $overrides['turnId'] ?? 'turn-1',
        conversationId: $overrides['conversationId'] ?? 'conversation-1',
        leadId: $overrides['leadId'] ?? 'lead-1',
        context: $overrides['context'] ?? makeSharedDecisionContext(),
        ruleInterpretation: $ruleInterpretation,
        classifierResult: $classifierResult,
        currentStage: $overrides['currentStage'] ?? ConversationStage::Qualification,
        ruleSignals: $overrides['ruleSignals'] ?? [],
        structuredState: $overrides['structuredState'] ?? [],
        businessFlags: $overrides['businessFlags'] ?? [],
        fallbackEligible: $overrides['fallbackEligible'] ?? false,
    );
}

test('turn decision service handles aligned rule and classifier', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput());

    expect($decision->conflicts)->toBe([])
        ->and($decision->finalDecision['intent'])->toBe('tanya_paket')
        ->and($decision->finalDecision['action'])->toBe(FinalAction::ReplyWithPackageDetails)
        ->and($decision->finalDecision['stage_after'])->toBe(ConversationStage::PackageRecommendation->value)
        ->and($decision->notes)->toContain('Rule and classifier aligned.');
});

test('turn decision service records conflicting rule and classifier intents', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput([
        'context' => makeSharedDecisionContext(ConversationStage::PackageRecommendation->value),
        'currentStage' => ConversationStage::PackageRecommendation,
        'classifierResult' => new ClassifierOutput(
            intent: 'payment_inquiry',
            sentiment: 'neutral',
            extractedFields: [],
            needsHandoff: false,
            handoffReason: null,
            confidence: 0.91,
            currentStage: ConversationStage::PackageRecommendation,
            suggestedNextStage: ConversationStage::PaymentDiscussion,
            missingCriticalFields: [],
        ),
    ]));

    expect($decision->conflicts)->toHaveCount(1)
        ->and($decision->finalDecision['intent'])->toBe('tanya_paket')
        ->and($decision->notes)->toContain('Rule and classifier conflicted; stage-consistent intent was selected.');
});

test('turn decision service applies stage-consistent override toward current payment stage', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput([
        'context' => makeSharedDecisionContext(ConversationStage::PaymentDiscussion->value),
        'currentStage' => ConversationStage::PaymentDiscussion,
        'classifierResult' => new ClassifierOutput(
            intent: 'payment_inquiry',
            sentiment: 'neutral',
            extractedFields: [],
            needsHandoff: false,
            handoffReason: null,
            confidence: 0.91,
            currentStage: ConversationStage::PaymentDiscussion,
            suggestedNextStage: ConversationStage::PaymentDiscussion,
            missingCriticalFields: [],
        ),
    ]));

    expect($decision->finalDecision['intent'])->toBe('payment_inquiry')
        ->and($decision->finalDecision['stage_after'])->toBe(ConversationStage::PaymentDiscussion->value)
        ->and($decision->notes)->toContain('Rule and classifier conflicted; stage-consistent intent was selected.');
});

test('turn decision service selects fallback action when the turn is fallback eligible', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput([
        'ruleInterpretation' => new InterpretationResult(
            canonicalIntent: 'unclear',
            legacyIntent: 'other',
            slots: [],
            confidence: 0.3,
            source: 'rules',
        ),
        'classifierResult' => new ClassifierOutput(
            intent: 'other',
            sentiment: 'neutral',
            extractedFields: [],
            needsHandoff: false,
            handoffReason: null,
            confidence: 0.44,
            currentStage: ConversationStage::Qualification,
            suggestedNextStage: ConversationStage::Qualification,
            missingCriticalFields: [],
        ),
        'fallbackEligible' => true,
        'businessFlags' => [
            'force_fallback_reason' => 'classifier_invalid_output_unclear_rules',
        ],
    ]));

    expect($decision->finalDecision['action'])->toBe(FinalAction::ReplyWithFallback)
        ->and($decision->finalDecision['fallback_reason'])->toBe('classifier_invalid_output_unclear_rules')
        ->and($decision->finalDecision['should_reply'])->toBeTrue();
});

test('turn decision service supports explicit do not reply decisions', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput([
        'businessFlags' => [
            'no_reply' => true,
            'no_reply_reason' => 'superseded_by_newer_inbound',
        ],
    ]));

    expect($decision->finalDecision['action'])->toBe(FinalAction::DoNotReply)
        ->and($decision->finalDecision['should_reply'])->toBeFalse()
        ->and($decision->finalDecision['fallback_reason'])->toBe('superseded_by_newer_inbound');
});

test('turn decision service supports handoff required decisions', function () {
    $decision = (new TurnDecisionService())->decide(makeTurnDecisionInput([
        'context' => makeSharedDecisionContext(ConversationStage::Closing->value),
        'currentStage' => ConversationStage::Closing,
        'ruleInterpretation' => new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_proof',
            slots: [],
            confidence: 0.94,
            source: 'rules',
        ),
        'classifierResult' => new ClassifierOutput(
            intent: 'payment_proof',
            sentiment: 'neutral',
            extractedFields: [],
            needsHandoff: true,
            handoffReason: 'payment_proof',
            confidence: 0.95,
            currentStage: ConversationStage::Closing,
            suggestedNextStage: ConversationStage::HandoffToHuman,
            missingCriticalFields: [],
        ),
        'businessFlags' => [
            'handoff_required' => true,
            'handoff_reason' => 'payment_proof',
        ],
    ]));

    expect($decision->finalDecision['action'])->toBe(FinalAction::RequestHumanHandoff)
        ->and($decision->finalDecision['requires_handoff'])->toBeTrue()
        ->and($decision->finalDecision['stage_after'])->toBe(ConversationStage::HandoffToHuman->value);
});
