<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;

function makeConversationStateService(): ConversationStateService
{
    $leadMemoryService = new LeadMemoryService();
    $bookingDataService = new LeadBookingDataService();
    $stageService = new ConversationStageService(new TransitionConversationStageAction());

    return new ConversationStateService(
        $leadMemoryService,
        $bookingDataService,
        $stageService,
        new ClosingPolicyService(
            $leadMemoryService,
            $bookingDataService,
            new LeadReadinessScorer(),
            new CtaSuggestionService(),
        ),
    );
}

test('recordInboundMessage creates explicit conversation state row', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'content' => 'Halo, saya mau tanya paket wedding',
    ]);

    $state = makeConversationStateService()->recordInboundMessage($message);

    expect($state)->toBeInstanceOf(ConversationState::class)
        ->and($state->conversation_id)->toBe($conv->id)
        ->and($state->lead_id)->toBe($lead->id)
        ->and($state->current_stage)->toBe('qualification')
        ->and($state->last_user_message)->toBe('Halo, saya mau tanya paket wedding');
});

test('applyInterpretationResult stores canonical intent, slots, and confidence', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'asked_fields' => ['event_date'],
        'next_expected_field' => 'location',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'name' => 'Budi',
        'event_date' => '2026-12-12',
        'location' => 'Bandung',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'positive',
        extractedFields: ['location' => 'Bandung'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['location', 'budget'],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'package_inquiry',
        legacyIntent: 'tanya_paket',
        slots: [
            'event_type' => 'wedding',
            'event_time_start' => '10:00',
            'location' => 'Bandung',
            'pricing_focus' => 'package_only',
        ],
        confidence: 0.91,
        source: 'rules+llm',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->current_intent)->toBe('package_inquiry')
        ->and($state->intent_confidence)->toBe(0.91)
        ->and($state->interpretation_source)->toBe('rules+llm')
        ->and($state->lead_temperature)->toBe('warm')
        ->and($state->filled_slots['event_type'])->toBe('wedding')
        ->and($state->filled_slots['name'])->toBe('Budi')
        ->and($state->filled_slots['event_date'])->toBe('2026-12-12')
        ->and($state->filled_slots['event_time_start'])->toBe('10:00')
        ->and($state->filled_slots['location'])->toBe('Bandung')
        ->and($state->filled_slots['pricing_focus'])->toBe('package_only')
        ->and($state->unresolved_questions)->not->toContain('service_type')
        ->and($state->unresolved_questions)->toContain('budget')
        ->and($state->next_best_action)->toBe('ask_location');
});

test('payment inquiry keeps next best action in closing flow instead of regressing to discovery', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'field_type' => BookingFieldType::Text,
        'sort_order' => 1,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'name' => 'Ayu',
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
        'budget_min' => 15000000,
    ]);

    $classifier = new ClassifierOutput(
        intent: 'payment_inquiry',
        sentiment: 'positive',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.93,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'payment_inquiry',
        legacyIntent: 'payment_inquiry',
        slots: ['payment_topic' => 'down_payment'],
        confidence: 0.93,
        source: 'rules',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->next_best_action)->toStartWith('answer_payment_then_')
        ->and($state->lead_temperature)->toBe('warm');
});

test('applyClassifierResult stores canonical intent instead of raw classifier labels', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    $classifier = new ClassifierOutput(
        intent: 'package_recommendation',
        sentiment: 'neutral',
        extractedFields: [
            'service_type' => 'lamaran',
            'package_interest' => 'foto dan video',
        ],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $state = makeConversationStateService()->applyClassifierResult($conv, $lead->fresh(), $classifier);

    expect($state->current_intent)->toBe('package_inquiry')
        ->and($state->interpretation_source)->toBe('llm')
        ->and($state->intent_confidence)->toBe(0.9)
        ->and($state->filled_slots['event_type'])->toBe('engagement')
        ->and($state->filled_slots['package_interest'])->toBe('photo + video');
});

test('applyInterpretationResult lets latest event correction override stale memory snapshot', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PackageRecommendation)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: ['service_type' => 'wedding'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: ConversationStage::PackageRecommendation,
        missingCriticalFields: [],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'package_inquiry',
        legacyIntent: 'tanya_paket',
        slots: ['event_type' => 'lamaran'],
        confidence: 0.93,
        source: 'rules+llm',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->filled_slots['event_type'])->toBe('engagement')
        ->and($state->filled_slots['service_type'])->toBe('engagement');
});

test('pricing intent asks for missing client basics before recommending packages', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: ['service_type' => 'wedding'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.87,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'package_inquiry',
        legacyIntent: 'tanya_paket',
        slots: ['event_type' => 'wedding'],
        confidence: 0.87,
        source: 'rules+llm',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->next_best_action)->toBe('ask_guest_count');
});

test('last_answered_topic is preserved on inbound interpretation and updated from real outbound reply', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_answered_topic' => 'booking',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'tanya_harga',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['event_date'],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'price_inquiry',
        legacyIntent: 'tanya_harga',
        slots: ['pricing_focus' => 'price_only'],
        confidence: 0.9,
        source: 'rules+llm',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->last_answered_topic)->toBe('booking');

    $state = makeConversationStateService()->recordOutboundMessage(
        $conv,
        $lead->fresh(),
        'Siap, biar aku arahin paket yang paling pas, boleh info tanggal acara dulu?',
        'ask_event_date',
    );

    expect($state->last_answered_topic)->toBe('event_date');
});

test('inbound interpretation clears stale last_agent_question after the asked field is answered', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_question' => 'Untuk tanggal berapa ya acaranya?',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'qualification',
        sentiment: 'neutral',
        extractedFields: ['event_date' => '2026-12-30'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['location'],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'unclear',
        legacyIntent: 'qualification',
        slots: ['event_date' => '2026-12-30'],
        confidence: 0.88,
        source: 'rules+llm',
    );

    $state = makeConversationStateService()->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

    expect($state->last_agent_question)->toBeNull()
        ->and($state->last_answered_topic)->toBe('event_date')
        ->and($state->filled_slots['event_date'])->toBe('2026-12-30');
});

test('loadOrCreate clears stale next_expected_field outside collection stages and leaves asked_fields untouched', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PackageRecommendation)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'asked_fields' => ['location'],
        'next_expected_field' => 'name',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'name' => 'Aris',
        'event_date' => '2026-12-12',
        'event_location' => 'Jakarta',
    ]);

    $state = makeConversationStateService()->loadOrCreate($conv->fresh(), $lead->fresh());
    $conv->refresh();

    expect($state->filled_slots['name'])->toBe('Aris')
        ->and($conv->next_expected_field)->toBeNull()
        ->and($conv->asked_fields)->toBe(['location']);
});

test('applyInterpretationResult recomputes next_expected_field from current snapshot in collection stages', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'name' => 'Aris',
        'event_date' => '2026-12-12',
    ]);

    $classifier = new ClassifierOutput(
        intent: 'qualification',
        sentiment: 'neutral',
        extractedFields: ['event_date' => '2026-12-12'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['location'],
    );

    $interpretation = new InterpretationResult(
        canonicalIntent: 'unclear',
        legacyIntent: 'qualification',
        slots: ['event_date' => '2026-12-12', 'name' => 'Aris'],
        confidence: 0.88,
        source: 'rules+llm',
    );

    makeConversationStateService()->applyInterpretationResult($conv->fresh(), $lead->fresh(), $interpretation, $classifier);
    $conv->refresh();

    expect($conv->asked_fields)->toBe(['service_type'])
        ->and($conv->next_expected_field)->toBe('location');
});

test('critical slots are rehydrated from lead memory snapshot into filled slots', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::FollowUp)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'preferred_packages' => ['gold'],
        'custom_fields' => [
            'pricing_focus' => 'price_and_package',
            'package_interest' => 'gold',
            'payment_topic' => 'down_payment',
            'event_time_start' => '10:00',
            'event_time_end' => '12:00',
        ],
    ]);

    $state = makeConversationStateService()->loadOrCreate($conv, $lead->fresh());

    expect($state->filled_slots['pricing_focus'])->toBe('price_and_package')
        ->and($state->filled_slots['package_interest'])->toBe('gold')
        ->and($state->filled_slots['payment_topic'])->toBe('down_payment')
        ->and($state->filled_slots['event_time_start'])->toBe('10:00')
        ->and($state->filled_slots['event_time_end'])->toBe('12:00');
});
