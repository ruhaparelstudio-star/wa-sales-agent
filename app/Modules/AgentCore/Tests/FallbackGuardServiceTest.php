<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\ContextAwareFallbackBuilder;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\FallbackGuardService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;

function makeFallbackGuardService(): FallbackGuardService
{
    $leadMemoryService = new LeadMemoryService();
    $stageService = new \App\Modules\Conversations\Services\ConversationStageService(new TransitionConversationStageAction());
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        new \App\Modules\Booking\Services\LeadBookingDataService(),
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );

    return new FallbackGuardService(
        new ContextAwareFallbackBuilder(
            $leadMemoryService,
            $stageService,
            $closingPolicyService,
        ),
    );
}

test('generic reset reply is replaced with payment-aware fallback', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
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

    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'DP-nya berapa ya?',
    ]);

    $result = makeFallbackGuardService()->guardGeneratedReply(
        'Yang paling ingin kamu cari tahu apa, Kak?',
        $lead,
        $conv,
        $message,
        null,
        new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_inquiry',
            slots: ['payment_topic' => 'down_payment'],
            confidence: 0.93,
            source: 'rules',
        ),
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toContain('DP')
        ->and($result['message'])->not->toContain('cari tahu apa')
        ->and($result['next_best_action'])->toStartWith('answer_payment_then_');
});

test('specific reply with clear intent does not trigger fallback replacement', function () {
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

    $result = makeFallbackGuardService()->guardGeneratedReply(
        'DP-nya 30% dari total, Kak. Bisa transfer ke rekening BCA.',
        $lead,
        $conv,
        $message,
        null,
        new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_inquiry',
            slots: ['payment_topic' => 'down_payment'],
            confidence: 0.95,
            source: 'rules',
        ),
    );

    expect($result)->toBeNull();
});

test('generic closing does not trigger fallback when reply already contains concrete payment answer', function () {
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

    $result = makeFallbackGuardService()->guardGeneratedReply(
        'DP-nya 30% dari total, Kak, dan transfernya ke rekening BCA. Ada yang bisa aku bantu lagi?',
        $lead,
        $conv,
        $message,
        ClassifierOutput::fromArray([
            'intent' => 'payment_inquiry',
            'sentiment' => 'neutral',
            'extracted_fields' => [],
            'needs_handoff' => false,
            'handoff_reason' => null,
            'confidence' => 0.95,
        ]),
        new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_inquiry',
            slots: ['payment_topic' => 'down_payment'],
            confidence: 0.95,
            source: 'rules',
        ),
    );

    expect($result)->toBeNull();
});

test('containsGenericResetLanguage returns false for a concrete answer', function () {
    $guard = makeFallbackGuardService();

    expect($guard->containsGenericResetLanguage('Tanggal 15 Mei masih tersedia, Kak.'))->toBeFalse()
        ->and($guard->containsGenericResetLanguage('DP 30%, sisa saat acara.'))->toBeFalse()
        ->and($guard->containsGenericResetLanguage(''))->toBeFalse();
});

test('containsGenericResetLanguage detects reset phrasing', function () {
    $guard = makeFallbackGuardService();

    expect($guard->containsGenericResetLanguage('Ada yang bisa saya bantu, Kak?'))->toBeTrue()
        ->and($guard->containsGenericResetLanguage('Silakan tanya apa saja'))->toBeTrue()
        ->and($guard->containsGenericResetLanguage('Siap, aku bantu jelaskan ya. Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?'))->toBeTrue();
});

test('pricing triage repeat is replaced with focused pricing fallback', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::FollowUp)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'last_agent_message' => 'Oke, berarti kita fokus ke harga dulu ya. Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu fokus ke bagian harganya.',
        'last_agent_question' => 'Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
    ]);

    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Harganya ka',
    ]);

    $result = makeFallbackGuardService()->guardGeneratedReply(
        'Siap, aku bantu jelaskan ya. Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
        $lead,
        $conv,
        $message,
        null,
        new InterpretationResult(
            canonicalIntent: 'price_inquiry',
            legacyIntent: 'tanya_harga',
            slots: ['pricing_focus' => 'price_only'],
            confidence: 0.91,
            source: 'rules',
        ),
    );

    expect($result)->not->toBeNull()
        ->and($result['message'])->toContain('fokus ke harga dulu')
        ->and($result['message'])->not->toContain('lebih perlu lihat harga, isi paket')
        ->and($result['tool_result_summary'])->toContain('generic_reset_reply_replaced:pricing_focus_price_only');
});

test('default pricing fallback copy does not match generic reset guard patterns', function () {
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

    $leadMemoryService = new LeadMemoryService();
    $stageService = new \App\Modules\Conversations\Services\ConversationStageService(new TransitionConversationStageAction());
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        new \App\Modules\Booking\Services\LeadBookingDataService(),
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );
    $builder = new ContextAwareFallbackBuilder(
        $leadMemoryService,
        $stageService,
        $closingPolicyService,
    );

    $fallback = $builder->build(
        $lead,
        $conv,
        $message,
        new InterpretationResult(
            canonicalIntent: 'price_inquiry',
            legacyIntent: 'tanya_harga',
            slots: [],
            confidence: 0.88,
            source: 'rules',
        ),
        null,
        'test_pricing_default',
    );

    $guard = makeFallbackGuardService();

    expect($guard->containsGenericResetLanguage($fallback['message']))->toBeFalse()
        ->and($fallback['message'])->toBe('Siap, aku bantu jelaskan paket atau harganya ya. Kalau ada bagian yang mau kamu lihat dulu, sebutkan aja, nanti aku lanjut dari situ.')
        ->and($fallback['message'])->toContain('paket atau harganya')
        ->and($fallback['message'])->not->toContain('lebih perlu lihat harga, isi paket')
        ->and($fallback['message'])->not->toContain('mana yang paling cocok buat kebutuhanmu');
});

test('package inquiry fallback in later stage stays on package topic instead of generic reset', function () {
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
        'content' => 'Aku mau lihat paket wedding',
    ]);

    $leadMemoryService = new LeadMemoryService();
    $stageService = new \App\Modules\Conversations\Services\ConversationStageService(new TransitionConversationStageAction());
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        new \App\Modules\Booking\Services\LeadBookingDataService(),
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );
    $builder = new ContextAwareFallbackBuilder(
        $leadMemoryService,
        $stageService,
        $closingPolicyService,
    );

    $fallback = $builder->build(
        $lead,
        $conv,
        $message,
        new InterpretationResult(
            canonicalIntent: 'package_inquiry',
            legacyIntent: 'tanya_paket',
            slots: [],
            confidence: 0.88,
            source: 'rules',
        ),
        null,
        'test_package_stage_default',
    );

    expect($fallback['message'])->toContain('isi paket')
        ->and($fallback['message'])->toContain('foto, video, atau album')
        ->and($fallback['message'])->not->toContain('gambaran acara atau paket yang diincar')
        ->and($fallback['next_best_action'])->toBe('respond_to_user');
});
