<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\ContextAwareFallbackBuilder;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\AgentCore\Services\QualityFilterService;
use App\Modules\AgentCore\Services\ResponseEvaluatorService;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;

function makeQualityFilterService(): QualityFilterService
{
    $leadMemoryService = new LeadMemoryService();
    $stageService = new \App\Modules\Conversations\Services\ConversationStageService(new TransitionConversationStageAction());
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        new \App\Modules\Booking\Services\LeadBookingDataService(),
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );

    return new QualityFilterService(
        new ResponseEvaluatorService(),
        new ContextAwareFallbackBuilder(
            $leadMemoryService,
            $stageService,
            $closingPolicyService,
        ),
    );
}

test('quality filter replaces reply that does not answer latest pricing question', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::FollowUp)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Aku mau tau harga dan isi paketnya ka',
    ]);

    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
    ]);

    $interpretation = new InterpretationResult(
        canonicalIntent: 'price_inquiry',
        legacyIntent: 'tanya_harga',
        slots: ['pricing_focus' => 'price_and_package'],
        confidence: 0.92,
        source: 'rules',
    );

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Kami siap bantu ya.',
        $lead,
        $conv,
        $message,
        $classifier,
        $interpretation,
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toContain('harga dan isi paket')
        ->and($result['tool_result_summary'])->toContain('quality_filter_replaced:unanswered_latest_question')
        ->and($result['evaluator_score']['answered_latest_question'])->toBeFalse();
});

test('quality filter replaces repeated question with contextual fallback', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Bandung',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_question' => 'Untuk tanggal berapa ya acaranya?',
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Baik kak, untuk tanggal berapa ya acaranya?',
        $lead,
        $conv,
        $message,
        null,
        null,
    );

    expect($result)->not->toBeNull()
        ->and($result['tool_result_summary'])->toContain('quality_filter_replaced:repeated_question')
        ->and($result['evaluator_score']['repeated_question'])->toBeTrue();
});

test('quality filter repairs repeated payment question while preserving the concrete answer', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'DP-nya berapa ya?',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PaymentDiscussion->value,
        'last_agent_question' => 'DP-nya berapa ya?',
        'next_best_action' => 'answer_payment_question',
    ]);

    $classifier = ClassifierOutput::fromArray([
        'intent' => 'payment_inquiry',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.94,
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'DP-nya 30% dari total ya, Kak. DP-nya berapa ya?',
        $lead,
        $conv,
        $message,
        $classifier,
        new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_inquiry',
            slots: ['payment_topic' => 'down_payment'],
            confidence: 0.94,
            source: 'rules',
        ),
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toBe('DP-nya 30% dari total ya, Kak.')
        ->and($result['rewrite_reason'])->toBe('repair:repeated_question')
        ->and($result['tool_result_summary'])->toContain('quality_filter_repaired:repeated_question:tier_medium');
});

test('quality filter keeps concrete safe answer when evaluator passes', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::FollowUp)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Aku mau tau harga paketnya',
    ]);

    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Harga paket kami mulai dari Rp15 juta untuk opsi yang paling basic.',
        $lead,
        $conv,
        $message,
        $classifier,
        null,
    );

    expect($result)->toBeNull();
});

test('quality filter repairs missing cta without full fallback when only soft failure is present', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Closing)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Kalau paket yang cocok yang mana ya?',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'next_best_action' => 'guide_to_booking',
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Untuk coverage lengkap, Paket Gold biasanya paling aman untuk acara yang butuh dokumentasi lebih padat.',
        $lead,
        $conv,
        $message,
        null,
        null,
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toContain('Paket Gold biasanya paling aman')
        ->and($result['message'])->toContain('lanjut ke langkah booking')
        ->and($result['tool_result_summary'])->toContain('quality_filter_repaired:missing_cta:tier_soft')
        ->and($result['next_best_action'])->toBe('guide_to_booking')
        ->and($result['evaluator_score']['has_cta_when_due'])->toBeFalse();
});

test('quality filter still prefers full fallback when missing cta is combined with medium failure', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Closing)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Aku mau tau harga paketnya',
    ]);

    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.93,
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Kami siap bantu ya.',
        $lead,
        $conv,
        $message,
        $classifier,
        null,
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->not->toBe('Kami siap bantu ya. Kalau sudah cocok, kita bisa lanjut ke langkah booking ya.')
        ->and($result['tool_result_summary'])->toContain('quality_filter_replaced:unanswered_latest_question,missing_cta:tier_medium')
        ->and($result['evaluator_score']['answered_latest_question'])->toBeFalse()
        ->and($result['evaluator_score']['has_cta_when_due'])->toBeFalse();
});

test('quality filter replaces near-duplicate reply with a different contextual fallback', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::FollowUp)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Aku mau lihat isi paketnya',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'last_agent_message' => 'Paket Gold biasanya paling cocok untuk acara yang butuh dokumentasi lengkap.',
        'next_best_action' => 'respond_to_user',
    ]);

    $interpretation = new InterpretationResult(
        canonicalIntent: 'package_inquiry',
        legacyIntent: 'tanya_paket',
        slots: ['pricing_focus' => 'package_only'],
        confidence: 0.9,
        source: 'rules',
    );

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Paket Gold biasanya paling cocok untuk acara yang butuh dokumentasi lengkap!',
        $lead,
        $conv,
        $message,
        null,
        $interpretation,
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->not->toBe('Paket Gold biasanya paling cocok untuk acara yang butuh dokumentasi lengkap!')
        ->and($result['message'])->toContain('fokus ke isi paket dulu')
        ->and($result['next_best_action'])->toBe('respond_to_user')
        ->and($result['tool_result_summary'])->toContain('quality_filter_replaced:duplicate_reply:tier_hard');
});

test('quality filter uses improved pricing fallback instead of handoff when replacement is no longer duplicate', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PackageRecommendation)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Bisa dibantu jelasin?',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Kalau sudah ada gambaran acara atau paket yang diincar, sebutkan aja, nanti aku lanjut dari situ.',
        'next_best_action' => 'respond_to_user',
    ]);

    $interpretation = new InterpretationResult(
        canonicalIntent: 'price_inquiry',
        legacyIntent: 'tanya_harga',
        slots: [],
        confidence: 0.88,
        source: 'rules',
    );

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Siap, aku bantu jelaskan ya. Kalau sudah ada gambaran acara atau paket yang diincar, sebutkan aja, nanti aku lanjut dari situ.',
        $lead,
        $conv,
        $message,
        null,
        $interpretation,
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toBe('Siap, aku bantu jelaskan paket atau harganya ya. Kalau ada bagian yang mau kamu lihat dulu, sebutkan aja, nanti aku lanjut dari situ.')
        ->and($result['next_best_action'])->toBe('respond_to_user')
        ->and($result)->not->toHaveKey('handoff_reason_detail')
        ->and($result['tool_result_summary'])->toContain('quality_filter_replaced:duplicate_reply:tier_hard');
});

test('quality filter does not replace relevant package answer with booking or payment topic', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Kalo prewedding package dpet apa aja kak',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PaymentDiscussion->value,
        'next_best_action' => 'guide_to_booking',
    ]);

    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.93,
    ]);

    $result = makeQualityFilterService()->filterGeneratedReply(
        'Kalau prewedding biasanya termasuk sesi foto di lokasi pilihan, editing foto, dan hasil digital.',
        $lead,
        $conv,
        $message,
        $classifier,
        new InterpretationResult(
            canonicalIntent: 'package_inquiry',
            legacyIntent: 'tanya_paket',
            slots: ['package_interest' => 'prewedding'],
            confidence: 0.93,
            source: 'rules',
        ),
    );

    expect($result)->toBeNull();
});
