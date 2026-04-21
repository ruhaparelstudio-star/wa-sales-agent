<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationStageTransition;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;

beforeEach(function () {
    $this->stageService = new ConversationStageService(new TransitionConversationStageAction());
});

function makeConvAtStage(ConversationStage $stage): Conversation
{
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    return Conversation::factory()->atStage($stage)->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);
}

test('new_lead -> qualification is a valid transition', function () {
    $conv = makeConvAtStage(ConversationStage::NewLead);
    $out  = new ClassifierOutput(
        intent: 'greeting',
        sentiment: 'positive',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::NewLead,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out);
    $conv->refresh();

    expect($result)->toBe(ConversationStage::Qualification)
        ->and($conv->stageEnum())->toBe(ConversationStage::Qualification)
        ->and($conv->stage_transition_count)->toBe(1);

    expect(ConversationStageTransition::where('conversation_id', $conv->id)->count())->toBe(1);
});

test('classifier suggested stage is advisory only when it conflicts with runtime rules', function () {
    $conv = makeConvAtStage(ConversationStage::PaymentDiscussion);
    $out  = new ClassifierOutput(
        intent: 'other',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.5,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out);
    $conv->refresh();

    expect($result)->toBe(ConversationStage::PaymentDiscussion)
        ->and($conv->stageEnum())->toBe(ConversationStage::PaymentDiscussion);
    expect(ConversationStageTransition::where('conversation_id', $conv->id)->count())->toBe(0);
});

test('pricing inquiry stays in qualification when required qualification memory is still partial', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);
    $out  = new ClassifierOutput(
        intent: 'tanya_harga',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Closing,
        missingCriticalFields: ['event_date', 'location'],
    );

    $result = $this->stageService->decideAndApply($conv, $out, [
        'service_type' => 'wedding',
    ]);

    expect($result)->toBe(ConversationStage::Qualification)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::Qualification);
});

test('payment inquiry promotes conversation without regressing even when classifier suggestion is stale', function () {
    $conv = makeConvAtStage(ConversationStage::PackageRecommendation);
    $out  = new ClassifierOutput(
        intent: 'payment_inquiry',
        sentiment: 'positive',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.94,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out);

    expect($result)->toBe(ConversationStage::PaymentDiscussion)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PaymentDiscussion);
});

test('payment stage requires explicit payment lexicon', function () {
    $conv = makeConvAtStage(ConversationStage::PackageRecommendation);
    $out  = new ClassifierOutput(
        intent: 'payment_inquiry',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.92,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out, [
        'event_date' => '2026-04-18',
        'event_location' => 'Jakarta',
    ], 'Kalo prewedding package dapet apa aja kak');

    expect($result)->toBe(ConversationStage::PackageRecommendation)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PackageRecommendation);
});

test('ready_to_book promotes into closing even when classifier suggested stage is stale', function () {
    $conv = makeConvAtStage(ConversationStage::PackageRecommendation);
    $out  = new ClassifierOutput(
        intent: 'ready_to_book',
        sentiment: 'positive',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.95,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out);

    expect($result)->toBe(ConversationStage::Closing)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::Closing);
});

test('ready_to_book from qualification still advances state through booking flow', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);
    $out  = new ClassifierOutput(
        intent: 'ready_to_book',
        sentiment: 'positive',
        extractedFields: ['event_date' => '2026-04-18'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.96,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Closing,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out, [
        'event_date' => '2026-04-18',
        'event_location' => 'Jakarta',
    ], 'Aku mau booking untuk acara tanggal 18 April 2026 bisa kak?');

    expect($result)->toBe(ConversationStage::PaymentDiscussion)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PaymentDiscussion);
});

test('ambiguous intent with partial memory does not promote stage from stale suggestion', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);
    $out  = new ClassifierOutput(
        intent: 'other',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.5,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::PackageRecommendation,
        missingCriticalFields: ['event_date', 'location'],
    );

    $result = $this->stageService->decideAndApply($conv, $out, [
        'service_type' => 'wedding',
    ]);

    expect($result)->toBe(ConversationStage::Qualification)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::Qualification);
});

test('direct pricelist inquiry promotes qualification stage into package recommendation', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);

    $result = $this->stageService->promoteForDirectPricelistInquiry($conv);

    expect($result)->toBe(ConversationStage::PackageRecommendation)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PackageRecommendation);
});

test('direct pricelist inquiry does not regress payment discussion', function () {
    $conv = makeConvAtStage(ConversationStage::PaymentDiscussion);

    $result = $this->stageService->promoteForDirectPricelistInquiry($conv);

    expect($result)->toBe(ConversationStage::PaymentDiscussion)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PaymentDiscussion);
});

test('state correction from payment discussion returns to package recommendation for package questions', function () {
    $conv = makeConvAtStage(ConversationStage::PaymentDiscussion);
    $out  = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out, [
        'event_date' => '2026-04-18',
        'event_location' => 'Jakarta',
    ], 'isi paket apa aja');

    expect($result)->toBe(ConversationStage::PackageRecommendation)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PackageRecommendation);
});

test('needsHandoff forces transition to handoff_to_human from any active stage', function () {
    $conv = makeConvAtStage(ConversationStage::PackageRecommendation);
    $out  = new ClassifierOutput(
        intent: 'complaint',
        sentiment: 'negative',
        extractedFields: [],
        needsHandoff: true,
        handoffReason: 'complaint',
        confidence: 0.95,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: null,
        missingCriticalFields: [],
    );

    $result = $this->stageService->decideAndApply($conv, $out);

    expect($result)->toBe(ConversationStage::HandoffToHuman);
});

test('missing discovery fields only uses the active collection stage and excludes already asked', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);
    $conv->forceFill(['asked_fields' => ['event_date']])->save();

    $missing = $this->stageService->missingDiscoveryFields($conv, []);
    $next    = $this->stageService->nextExpectedField($conv, []);

    expect($missing)->not->toContain('event_date')
        ->and($missing)->not->toContain('guest_count')
        ->and($next)->toBe('location');
});

test('missing discovery fields exclude values already in lead memory snapshot', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);

    $missing = $this->stageService->missingDiscoveryFields($conv, [
        'event_location' => 'Bandung',
    ]);

    expect($missing)->not->toContain('location')
        ->and($missing)->not->toContain('name');
});

test('missing recommendation fields require guest count and budget before recommendation', function () {
    $conv = makeConvAtStage(ConversationStage::PaymentDiscussion);

    $missing = $this->stageService->missingRecommendationFields($conv, [
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
    ]);

    expect($missing)->toContain('guest_count')
        ->and($missing)->toContain('budget')
        ->and($this->stageService->nextRecommendationField($conv, [
            'event_date' => '2026-12-12',
            'event_location' => 'Bandung',
        ]))->toBe('guest_count');
});

test('next expected field after asking advances inside the current stage sequence', function () {
    $conv = makeConvAtStage(ConversationStage::Qualification);

    $next = $this->stageService->nextExpectedFieldAfterAsking($conv, [], 'event_date');

    expect($next)->toBe('location');
});

test('stage graph allowed_next matches the conversion-focused specification', function () {
    expect(ConversationStage::NewLead->allowedNext())
        ->toEqualCanonicalizing([ConversationStage::Qualification, ConversationStage::HandoffToHuman]);

    expect(ConversationStage::PaymentDiscussion->allowedNext())
        ->toEqualCanonicalizing([
            ConversationStage::PackageRecommendation,
            ConversationStage::ObjectionHandling,
            ConversationStage::Closing,
            ConversationStage::Booked,
            ConversationStage::HandoffToHuman,
        ]);

    expect(ConversationStage::Closed->allowedNext())->toBe([]);
});

test('stage transitions table scopes by tenant (no cross-tenant leakage)', function () {
    $convA = makeConvAtStage(ConversationStage::NewLead);
    $convB = makeConvAtStage(ConversationStage::NewLead);

    (new TransitionConversationStageAction())->execute($convA, ConversationStage::Qualification, 'rule', 'test-a');
    (new TransitionConversationStageAction())->execute($convB, ConversationStage::Qualification, 'rule', 'test-b');

    $aRows = ConversationStageTransition::forTenant($convA->tenant_id)->get();
    $bRows = ConversationStageTransition::forTenant($convB->tenant_id)->get();

    expect($aRows->pluck('conversation_id')->all())->toBe([$convA->id])
        ->and($bRows->pluck('conversation_id')->all())->toBe([$convB->id]);
});
