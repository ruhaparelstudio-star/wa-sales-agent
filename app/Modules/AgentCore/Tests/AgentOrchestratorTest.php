<?php

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\ConversationInterpretationService;
use App\Modules\AgentCore\Services\ContextAwareFallbackBuilder;
use App\Modules\AgentCore\Services\ContextAssembler;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\DelayPolicyService;
use App\Modules\AgentCore\Services\FallbackGuardService;
use App\Modules\AgentCore\Services\GuardrailService;
use App\Modules\AgentCore\Services\HumanizerService;
use App\Modules\AgentCore\Services\IntentExtractionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\AgentCore\Services\PromptBuilder;
use App\Modules\AgentCore\Services\QualityFilterService;
use App\Modules\AgentCore\Services\RiskPolicyService;
use App\Modules\AgentCore\Services\ResponsePlannerService;
use App\Modules\AgentCore\Services\SlotExtractionService;
use App\Modules\AgentCore\Tests\Support\FakeLlmClient;
use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Booking\Services\BookingSchemaService;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Actions\RecordAskedFieldAction;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationSummary;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\WhatsApp\Jobs\SendOutboundDocumentJob;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionEnforcementService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Jobs\SendOutboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;


beforeEach(function () {
    Cache::flush();
    Queue::fake();
    Storage::fake('local');
});

function buildOrchestrator(LlmClientInterface $llm, ?BookingSchemaService $bookingSchemaService = null): AgentOrchestrator
{
    $leadService = new LeadService();
    $leadMemoryService = new LeadMemoryService();
    $leadStageService = new LeadStageService($leadService);
    $convService = new ConversationService();
    $summaryService = new ConversationSummaryService();

    $transitionAction = new TransitionConversationStageAction();
    $stageService     = new ConversationStageService($transitionAction);
    $bookingDataService = new LeadBookingDataService();
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        $bookingDataService,
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );
    $contextAwareFallbackBuilder = new ContextAwareFallbackBuilder(
        $leadMemoryService,
        $stageService,
        $closingPolicyService,
    );
    $fallbackGuardService = new FallbackGuardService($contextAwareFallbackBuilder);

    $contextAssembler = new ContextAssembler(
        new PromptBuilder(),
        $leadMemoryService,
        new App\Modules\Knowledge\Services\KnowledgeRetrievalService(),
        $convService,
        $summaryService,
        $bookingDataService,
        $stageService,
        new ConversationStateService(
            $leadMemoryService,
            $bookingDataService,
            $stageService,
            $closingPolicyService,
        ),
        $closingPolicyService,
        new ResponsePlannerService($closingPolicyService),
    );

    $subService  = new SubscriptionService();
    $slotService = app(AgentSlotPolicyService::class);
    $enforcement = new SubscriptionEnforcementService($subService, $slotService);
    $guardrail   = new GuardrailService($enforcement, new RiskPolicyService());

    $handoffService = new HandoffRequestService($convService, $leadService, $leadStageService);

    $dispatchService = app(OutboundDispatchService::class);

    $bookingSchemaService ??= new BookingSchemaService();

    return new AgentOrchestrator(
        $llm,
        $contextAssembler,
        $guardrail,
        new HumanizerService(),
        new QualityFilterService(
            new \App\Modules\AgentCore\Services\ResponseEvaluatorService(),
            $contextAwareFallbackBuilder,
        ),
        new RiskPolicyService(),
        new DelayPolicyService(),
        $leadService,
        $leadMemoryService,
        $leadStageService,
        $handoffService,
        $dispatchService,
        $summaryService,
        new PricelistService(),
        new KnowledgeRetrievalService(),
        $bookingSchemaService,
        $bookingDataService,
        $stageService,
        new RecordAskedFieldAction(),
        new ConversationStateService(
            $leadMemoryService,
            $bookingDataService,
            $stageService,
            $closingPolicyService,
        ),
        new ConversationInterpretationService(
            new IntentExtractionService(),
            new SlotExtractionService(),
        ),
        $contextAwareFallbackBuilder,
        $fallbackGuardService,
        new \App\Modules\AgentCore\Services\ResponseEvaluatorService(),
        new \App\Modules\AgentCore\Services\TurnDecisionService(),
        new \App\Modules\AgentCore\Handlers\BookingFieldReplyHandler(
            $bookingDataService,
            new \App\Modules\Booking\Services\BookingFieldValidationService(),
        ),
        new \App\Modules\AgentCore\Services\BusinessPayloadResponder(),
    );
}

function setupInboundScenario(array $tenantOverrides = []): array
{
    $tenant = Tenant::factory()->create(array_merge([
        'settings' => [
            'quiet_hours_start' => '03:00',
            'quiet_hours_end'   => '03:05',
            'follow_up_max'     => 2,
        ],
    ], $tenantOverrides));
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id]);
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead  = Lead::factory()->create([
        'tenant_id'         => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
    ]);
    $conv  = Conversation::factory()->active()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
        'direction'       => MessageDirection::Inbound,
        'content'         => 'halo, paketnya ada apa aja?',
    ]);

    return compact('tenant', 'agent', 'lead', 'conv', 'message');
}

test('ready_to_book sends booking form and does not create a new handoff', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create([
        'form_type' => FormType::Booking,
    ]);
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'field_type' => BookingFieldType::Text,
        'sort_order' => 1,
    ]);
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Tanggal Acara',
        'field_key' => 'tanggal_acara',
        'field_type' => BookingFieldType::Text,
        'sort_order' => 2,
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent'           => 'ready_to_book',
        'sentiment'        => 'positive',
        'extracted_fields' => ['name' => null, 'event_date' => null, 'location' => null, 'budget' => null, 'service_type' => null, 'guest_count' => null],
        'needs_handoff'    => true,
        'handoff_reason'   => 'ready_to_book',
        'confidence'       => 0.95,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    expect(HandoffRequest::count())->toBe(0)
        ->and($lead->fresh()->automation_paused)->toBeFalse();

    expect($llm->calls)->toHaveCount(1);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'kita lanjut ke booking')
            && str_contains($job->content, 'Nama Lengkap')
            && str_contains($job->content, 'Tanggal Acara');
    });
});

test('short confirmation after detail offer stays in package explanation flow instead of closing', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $conv->update(['stage' => ConversationStage::PackageRecommendation]);
    ConversationState::factory()->create([
        'tenant_id' => $conv->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'package_inquiry',
        'last_agent_message' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut.',
        'last_agent_question' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut?',
    ]);
    $message->update(['content' => 'boleh ka']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'ready_to_book',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('Siap, aku jelaskan detail paket Photo + Album dulu ya.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $turn = \App\Modules\AgentCore\Models\ConversationTurnLog::where('message_id', $message->id)->first();

    expect($conv->fresh()->stage)->toBe(ConversationStage::PackageRecommendation)
        ->and($turn?->intent)->toBe('tanya_paket')
        ->and($turn?->response_type)->toBe('text');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'jelaskan detail paket')
            && ! str_contains(strtolower($job->content), 'lanjut ke booking');
    });
});

test('user objection after forced closing is handled as clarification instead of booking', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $conv->update(['stage' => ConversationStage::Closing]);
    ConversationState::factory()->create([
        'tenant_id' => $conv->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'current_intent' => 'booking_intent',
        'last_agent_message' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut.',
        'last_agent_question' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut?',
    ]);
    $message->update(['content' => 'kenapa langsung ke booking katanya mau kamu jelaskan']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'objection_handling',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => ConversationStage::Closing->value,
        'suggested_next_stage' => ConversationStage::Closing->value,
    ]));
    $llm->queueResponse('Maaf ya, harusnya aku jelaskan detail paketnya dulu. Aku bantu lanjut dari situ.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $turn = \App\Modules\AgentCore\Models\ConversationTurnLog::where('message_id', $message->id)->first();

    expect($conv->fresh()->stage)->toBe(ConversationStage::ObjectionHandling)
        ->and($turn?->intent)->toBe('complaint')
        ->and($turn?->response_type)->toBe('text');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'harusnya aku jelaskan detail paketnya dulu')
            && ! str_contains(strtolower($job->content), 'lanjut ke booking');
    });
});

test('booking schema failure falls back safely and duplicate retry does not resend booking flow', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'oke aku mau booking ya']);

    $failingSchemaService = new class extends BookingSchemaService
    {
        public function getActiveSchema(Tenant $tenant, FormType $type): ?BookingFormTemplate
        {
            throw new \TypeError('__PHP_Incomplete_Class returned');
        }
    };

    $llm = new FakeLlmClient();
    $classifierPayload = json_encode([
        'intent' => 'ready_to_book',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.95,
    ]);
    $llm->queueResponse($classifierPayload);
    $llm->queueResponse($classifierPayload);

    $orchestrator = buildOrchestrator($llm, $failingSchemaService);
    $orchestrator->handleInbound($message, $lead, $conv->fresh());
    $orchestrator->handleInbound($message->fresh(), $lead->fresh(), $conv->fresh());

    $state = $conv->fresh()->state()->first();

    expect($state?->last_tool_result_summary)->toBe('booking_schema_error:message:' . $message->id);

    Queue::assertPushed(SendOutboundMessageJob::class, 1);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'flow booking lagi aku tahan dulu')
            && str_contains($job->content, 'jelaskan detail paket');
    });
});

test('intent resolution logging records raw rule final and override reason', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $conv->update(['stage' => ConversationStage::PackageRecommendation]);
    ConversationState::factory()->create([
        'tenant_id' => $conv->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'package_inquiry',
        'last_agent_message' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut.',
        'last_agent_question' => 'Kalau mau, aku bisa bantu jelaskan detail paket ini lebih lanjut?',
    ]);
    $message->update(['content' => 'boleh ka']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'ready_to_book',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('Siap, aku jelaskan detail paketnya dulu ya.');

    $logPath = storage_path('logs/agent-'.now()->format('Y-m-d').'.log');
    $before = file_exists($logPath) ? file_get_contents($logPath) : '';

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $after = file_exists($logPath) ? file_get_contents($logPath) : '';

    expect($after)->not->toBe($before)
        ->and($after)->toContain('intent.resolution')
        ->and($after)->toContain('raw_analyzer_intent')
        ->and($after)->toContain('rule_intent')
        ->and($after)->toContain('final_intent')
        ->and($after)->toContain('override_reason');
});

test('opt_out → pauses automation and does not send reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent'           => 'opt_out',
        'sentiment'        => 'negative',
        'extracted_fields' => [],
        'needs_handoff'    => true,
        'handoff_reason'   => 'opt_out',
        'confidence'       => 0.99,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    expect($lead->fresh()->automation_paused)->toBeTrue()
        ->and(HandoffRequest::count())->toBe(1);

    // Opt-out still sends a single acknowledgment message confirming
    // we won't contact the user again.
    Queue::assertPushed(SendOutboundMessageJob::class, 1);
});

test('guardrail blocked lead skips LLM entirely', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $lead->update(['automation_paused' => true]);

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv);

    expect($llm->calls)->toHaveCount(0);
    Queue::assertNotPushed(SendOutboundMessageJob::class);
});

test('non pricing intent generates reply and queues outbound', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'halo kak, aku lagi cari vendor wedding untuk desember']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent'           => 'other',
        'sentiment'        => 'positive',
        'extracted_fields' => ['name' => 'Budi'],
        'needs_handoff'    => false,
        'handoff_reason'   => null,
        'confidence'       => 0.9,
    ]));
    $llm->queueResponse('Halo kak Budi, paket kami ada Silver, Gold, dan Platinum.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    expect($llm->calls)->toHaveCount(2)
        ->and($lead->fresh()->memory->name)->toBe('Budi')
        ->and(HandoffRequest::count())->toBe(0);

    Queue::assertPushed(SendOutboundMessageJob::class);
});

test('semantically correct payment answer survives rewrite chain and keeps key facts', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'DP-nya berapa ya?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'payment_inquiry',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.95,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'payment_discussion',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('DP-nya 30% dari total, Kak, dan transfernya ke rekening BCA. Ada yang bisa aku bantu lagi?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, '30%')
            && str_contains($content, 'rekening bca')
            && ! str_contains($content, 'kalau sudah ada gambaran acara atau paket yang diincar')
            && ! str_contains($content, 'yang paling ingin kamu cari tahu apa');
    });

    $state = $conv->fresh()->state()->first();

    expect($conv->fresh()->stageEnum())->toBe(ConversationStage::PaymentDiscussion)
        ->and($state?->last_tool_result_summary)->toBeNull()
        ->and($state?->last_agent_message)->toContain('30%')
        ->and($state?->last_agent_message)->toContain('rekening BCA');
});

test('package question after booking context stays on package topic and does not enter payment discussion', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Kalo prewedding package dpet apa aja kak']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    ConversationState::factory()->create([
        'tenant_id' => $conv->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'booking_intent',
        'last_agent_message' => 'Kalau sudah cocok, kita bisa lanjut booking ya.',
        'next_best_action' => 'guide_to_booking',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('Kalau prewedding biasanya termasuk sesi foto di lokasi pilihan, editing foto, dan hasil dalam bentuk digital.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $turn = \App\Modules\AgentCore\Models\ConversationTurnLog::where('message_id', $message->id)->first();

    expect($conv->fresh()->stageEnum())->toBe(ConversationStage::PackageRecommendation)
        ->and($turn?->intent)->toBe('tanya_paket');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'prewedding')
            && str_contains($content, 'editing foto')
            && ! str_contains($content, 'dp')
            && ! str_contains($content, 'jam acara');
    });
});

test('true booking intent advances state from qualification', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku mau booking untuk acara tanggal 18 April 2026 bisa kak?']);
    $conv->update(['stage' => ConversationStage::Qualification]);

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create([
        'form_type' => FormType::Booking,
    ]);
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'field_type' => BookingFieldType::Text,
        'sort_order' => 1,
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'positive',
        'extracted_fields' => ['event_date' => '18 April 2026'],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($conv->fresh()->stageEnum())->toBe(ConversationStage::PaymentDiscussion);
});

test('empty non pricing response falls back to a safe reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'aku lagi cari vendor wedding di bandung']);
    $conv->update(['stage' => ConversationStage::Qualification]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('   ');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'boleh info')
            && ! str_contains($content, 'pesanmu sudah kami terima')
            && ! str_contains($content, 'mau tanya apa');
    });
});

test('empty greeting response falls back to greeting-specific reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Siang ka']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.85,
    ]));
    $llm->queueResponse('');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'Halo Kak');
    });
});

test('generic reset response from llm is replaced with stage-aware qualification fallback', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'mau lihat paket wedding dong']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Yang paling ingin kamu cari tahu apa, Kak?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'boleh info')
            && ! str_contains($content, 'cari tahu apa');
    });
});

test('empty discount response falls back to discount-specific reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Ada diskon ga min']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.85,
    ]));
    $llm->queueResponse('');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'promo yang sedang aktif');
    });
});

test('pricing intent in package presentation answers in chat without forcing pricelist document', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'harganya berapa kak?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'pricing_focus' => 'price_only',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Untuk harga, aku jelaskan dulu di chat ya kak. Kalau setelah itu kamu tetap mau versi lengkapnya, baru aku kirim pricelistnya.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    expect($llm->calls)->toHaveCount(2)
        ->and(HandoffRequest::count())->toBe(0);

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'aku jelaskan dulu di chat')
            && str_contains($content, 'baru aku kirim pricelistnya');
    });
});

test('pricing intent in package presentation keeps lead whatsapp jid when replying in chat', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'harganya berapa kak?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    $lead->update([
        'phone_e164' => '+244529684836573',
        'whatsapp_jid' => '244529684836573@lid',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'pricing_focus' => 'price_only',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Untuk harga, aku jelaskan dulu di chat ya kak biar lebih gampang dicek.');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) use ($lead) {
        return $job->to === $lead->fresh()->preferredWhatsAppRecipient();
    });
});

test('misclassified greeting as pricing does not send pricelist when message lacks pricing keywords', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Siang ka']);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('Halo juga, ada yang bisa aku bantu terkait layanan wedding kami?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'Halo Kak, aku siap bantu ya.');
    });
});

test('direct package inquiry in package recommendation answers concretely from package knowledge', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Paketnya apa aja ka?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    LeadMemory::query()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'service_type' => 'wedding',
        'event_date' => '2026-12-30',
        'event_location' => 'Bekasi',
        'guest_count' => 300,
        'budget_min' => 3000000,
    ]);

    KnowledgeItem::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Paket Silver',
        'content' => 'Include 6 jam dokumentasi foto, 1 fotografer, dan album basic.',
        'type' => KnowledgeType::Package,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    KnowledgeItem::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Paket Gold',
        'content' => 'Include 8 jam foto video, 2 crew, dan album premium.',
        'type' => KnowledgeType::Package,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.94,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->not->toBeEmpty();

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'paket silver')
            && str_contains($content, 'paket gold')
            && str_contains($content, 'dokumentasi foto')
            && ! str_contains($content, 'paket yang kamu incar');
    });
    Queue::assertNotPushed(SendOutboundDocumentJob::class);
});

test('direct wedding package inquiry prefers wedding package knowledge and answers concretely', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Paket wedding apa aja?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'package_inquiry',
        'filled_slots' => [
            'event_type' => 'wedding',
            'pricing_focus' => 'package_only',
        ],
        'next_best_action' => 'respond_to_user',
    ]);

    KnowledgeItem::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Paket Wedding Gold',
        'content' => 'Wedding package include 10 jam foto video, 2 fotografer, dan album premium.',
        'type' => KnowledgeType::Package,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    KnowledgeItem::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Paket Engagement Silver',
        'content' => 'Engagement package include 4 jam dokumentasi foto.',
        'type' => KnowledgeType::Package,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => ['service_type' => 'wedding', 'pricing_focus' => 'package_only'],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.93,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class);
    Queue::assertNotPushed(SendOutboundDocumentJob::class);
});

test('direct pricelist inquiry auto-promotes stage and queues pricelist pdf', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'minta pricelist dong kak']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['name'],
        'next_expected_field' => 'event_date',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(0)
        ->and(HandoffRequest::count())->toBe(0)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::PackageRecommendation);

    Queue::assertPushed(SendOutboundDocumentJob::class, function ($job) use ($lead) {
        return ($job->to === $lead->phone_e164 || $job->to === $lead->preferredWhatsAppRecipient())
            && str_contains((string) $job->followUpText, 'Pricelist PDF-nya sudah aku kirim');
    });
    Queue::assertNotPushed(SendOutboundMessageJob::class);

    $state = $conv->fresh()->state()->first();

    expect($state?->last_answered_topic)->toBe('pricing')
        ->and($state?->last_agent_message)->toContain('Pricelist PDF-nya sudah aku kirim')
        ->and($state?->last_tool_result_summary)->toBe('pricelist_pdf_queued');
});

test('direct pricelist inquiry persists pricing focus and package interest across memory and state', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'minta pricelist paket gold dong, aku mau lihat harga dan isi paketnya']);
    $conv->update(['stage' => ConversationStage::Qualification]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $memory = $lead->fresh()->memory;
    $state = $conv->fresh()->state()->first();

    expect($memory)->not->toBeNull()
        ->and($memory?->preferred_packages)->toContain('gold')
        ->and($memory?->custom_fields['package_interest'] ?? null)->toBe('gold')
        ->and($memory?->custom_fields['pricing_focus'] ?? null)->toBe('price_and_package')
        ->and($state?->filled_slots['package_interest'])->toBe('gold')
        ->and($state?->filled_slots['pricing_focus'])->toBe('price_and_package');
});

test('stale summary context does not override runtime stage rules from partial qualification memory', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Masih lihat-lihat dulu ya kak']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type', 'event_date'],
        'next_expected_field' => 'location',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
    ]);

    ConversationSummary::query()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'summary_text' => 'Summary lama: lead terlihat siap closing dan tinggal bahas booking.',
        'last_summarized_at' => now(),
        'message_count_at_summary' => 2,
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-12',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.62,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'closing',
        'missing_critical_fields' => ['location'],
    ]));
    $llm->queueResponse('Boleh info lokasi acaranya dulu?');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    $state = $conv->fresh()->state()->first();

    expect($conv->fresh()->stageEnum())->toBe(ConversationStage::Qualification)
        ->and($state?->current_stage)->toBe(ConversationStage::Qualification->value)
        ->and($state?->next_best_action)->toBe('respond_to_user')
        ->and(strtolower((string) $state?->last_agent_message))->toContain('lokasi acara');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains(strtolower($job->content), 'lokasi acara');
    });
});

test('user mentions pricing but outbound answer topic follows the final asked field in the queued reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'aku mau lihat paket weddingnya']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Boleh info tanggal acaranya dulu?');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class);

    $state = $conv->fresh()->state()->first();

    expect($state?->current_intent)->toBe('package_inquiry')
        ->and(strtolower((string) $state?->last_agent_message))->toContain('tanggal acara')
        ->and($state?->last_answered_topic)->toBe('event_date');
});

test('critical turn slots survive full turn into memory and state for downstream logic', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Paket gold untuk wedding, DP nya gimana ya? Acara jam 10-12']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'payment_inquiry',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => 'follow_up',
        'suggested_next_stage' => 'payment_discussion',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Untuk DP nanti aku bantu jelaskan ya, kalau ada paket yang kamu incar kita bisa lanjut dari situ.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    $memory = $lead->fresh()->memory;
    $state = $conv->fresh()->state()->first();

    expect($memory)->not->toBeNull()
        ->and($memory?->preferred_packages)->toContain('gold')
        ->and($memory?->custom_fields['package_interest'] ?? null)->toBe('gold')
        ->and($memory?->custom_fields['payment_topic'] ?? null)->toBe('down_payment')
        ->and($memory?->custom_fields['event_time_start'] ?? null)->toBe('10:00')
        ->and($memory?->custom_fields['event_time_end'] ?? null)->toBe('12:00')
        ->and($state?->filled_slots['package_interest'])->toBe('gold')
        ->and($state?->filled_slots['payment_topic'])->toBe('down_payment')
        ->and($state?->filled_slots['event_time_start'])->toBe('10:00')
        ->and($state?->filled_slots['event_time_end'])->toBe('12:00');
});

test('pdf follow up in pricing context queues pricelist document without falling out of context', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update([
        'content' => 'Ada file pdf nya ngga ?',
        'quoted_content' => 'Boleh minta pricelistnya ka',
    ]);
    $conv->update(['stage' => ConversationStage::Closing]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'current_intent' => 'price_inquiry',
        'last_answered_topic' => 'pricing',
        'next_best_action' => 'share_pricelist',
        'last_agent_message' => 'Oke, berarti kita fokus ke harga dulu ya. Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu fokus ke bagian harganya.',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(0);

    Queue::assertPushed(SendOutboundDocumentJob::class, function ($job) use ($lead) {
        return ($job->to === $lead->phone_e164 || $job->to === $lead->preferredWhatsAppRecipient())
            && str_contains((string) $job->followUpText, 'Pricelist PDF-nya sudah aku kirim');
    });
    Queue::assertNotPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'Mau kita fokus ke paket yang paling cocok');
    });
});

test('pdf follow up in pricing context falls back to pricelist missing reply when pdf is unavailable', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update([
        'content' => 'Ada file pdf nya ngga ?',
        'quoted_content' => 'Boleh minta pricelistnya ka',
    ]);
    $conv->update(['stage' => ConversationStage::Closing]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'current_intent' => 'price_inquiry',
        'last_answered_topic' => 'pricing',
        'next_best_action' => 'share_pricelist',
    ]);

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(0)
        ->and(HandoffRequest::count())->toBe(1)
        ->and(HandoffRequest::first()->reason_detail)->toBe('pricelist_missing');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'pricelist PDF belum tersedia');
    });
});

test('document follow up does not trigger pricelist path when corrected last answered topic is non pricing', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Ada file pdf nya ngga ?']);
    $conv->update(['stage' => ConversationStage::Closing]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'current_intent' => 'unclear',
        'last_answered_topic' => 'event_date',
        'next_best_action' => 'respond_to_user',
        'last_tool_result_summary' => null,
        'last_agent_message' => 'Siap, biar aku arahin paket yang paling pas, boleh info tanggal acara dulu?',
    ]);

    Storage::disk('local')->put("tenants/{$lead->tenant_id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.8,
        'current_stage' => 'closing',
        'suggested_next_stage' => 'closing',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Boleh info yang masih mau dicek dulu ya?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains(strtolower($job->content), 'mau dicek');
    });
});

test('generic reset under pricing intent rewrites to focused price and package reply instead of repeating triage', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku mau tau harga nya dan isi paketnya ka']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'last_agent_question' => 'Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'pricing_focus' => 'price_and_package',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => 'follow_up',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Siap, aku bantu jelaskan ya. Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(2)
        ->and($conv->fresh()->stageEnum())->toBe(ConversationStage::Qualification);

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'fokus ke harga')
            && str_contains($content, 'isi paket')
            && ! str_contains($content, 'lebih perlu lihat harga, isi paket');
    });
});

test('explicit price follow up answers in chat when user did not ask for document', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Harganya ka']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'current_intent' => 'price_inquiry',
        'filled_slots' => [
            'pricing_focus' => 'price_only',
            'package_interest' => null,
            'payment_topic' => null,
            'inquiry_fields' => [],
            'booking_fields' => [],
        ],
        'last_agent_question' => 'Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
        'last_agent_message' => 'Oke, berarti kita fokus ke harga dulu ya. Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu fokus ke bagian harganya.',
        'last_answered_topic' => 'pricing',
        'next_best_action' => 'respond_to_user',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'pricing_focus' => 'price_only',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
        'current_stage' => 'follow_up',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Untuk harga, paket wedding mulai dari opsi yang paling basic sampai yang lengkap. Kalau mau, aku jelaskan range dan isi paket yang paling relevan buat kebutuhanmu di chat ini.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(2);

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'untuk harga')
            && str_contains($content, 'chat ini');
    });
});

test('price follow up after user cannot open pdf stays in text flow and does not resend pricelist', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Bisa jelasin aja gak kak? Hp aku gabisa buka pdf']);
    $conv->update(['stage' => ConversationStage::PaymentDiscussion]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PaymentDiscussion->value,
        'current_intent' => 'price_inquiry',
        'filled_slots' => [
            'pricing_focus' => 'price_only',
            'package_interest' => 'gold',
            'payment_topic' => null,
            'inquiry_fields' => [],
            'booking_fields' => [],
        ],
        'last_answered_topic' => 'pricing',
        'next_best_action' => 'share_pricelist',
        'last_tool_result_summary' => 'pricelist_pdf_queued',
        'last_agent_message' => 'Pricelist PDF-nya sudah aku kirim ya. Kalau mau, aku bantu arahin paket yang paling cocok buat acara kamu.',
    ]);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'pricing_focus' => 'price_only',
            'package_interest' => 'gold',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.93,
        'current_stage' => 'payment_discussion',
        'suggested_next_stage' => 'payment_discussion',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Bisa kak. Aku jelasin langsung di chat ya, jadi kamu nggak perlu buka PDF dulu. Untuk paket Gold, opsi harganya aku rangkum dulu beserta isi utamanya supaya lebih gampang dicek.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'langsung di chat')
            && str_contains($content, 'nggak perlu buka pdf');
    });
});

test('generic reset under package inquiry rewrites to focused package reply instead of repeating triage', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku mau lihat isi paket nya ka']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'last_agent_question' => 'Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Kamu lagi lebih perlu lihat harga, isi paket, atau mana yang paling cocok buat kebutuhanmu?',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
    ]));
    $llm->queueResponse('Ada yang bisa aku bantu soal paketnya?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'fokus ke isi paket dulu')
            && ! str_contains($content, 'lebih perlu lihat harga, isi paket')
            && ! str_contains($content, 'ada yang bisa aku bantu');
    });
});

test('grounded package reply formats the matched wedding package instead of leaking prewedding headers', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Boleh minta detail paket wedding ka']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    LeadMemory::query()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'service_type' => 'wedding',
        'event_date' => '2026-12-30',
        'event_location' => 'Bekasi',
        'guest_count' => 300,
        'budget_min' => 4000000,
    ]);

    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'PREWEDDING PACKAGE',
        'sort_order' => 0,
        'content' => "1. Photo Only\n- Durasi: 11 jam\n- Tim: 1 fotografer\n- Include: 20 foto edit\n- Harga: Rp 2.065.000",
    ]);
    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'WEDDING PACKAGE',
        'sort_order' => 0,
        'content' => "1. Photo + Album\n- Durasi: 11 jam\n- Tim: 1 fotografer\n- Include: album leather cover, custom leather box, 50 foto edit\n- Harga: Rp 1.995.000\n\n2. Photo + Video + Album\n- Durasi: 11 jam\n- Tim: 1 fotografer + 2 videografer\n- Include: full video acara, album leather cover, custom box\n- Harga: Rp 4.250.000",
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-30',
            'location' => 'Bekasi',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.86,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->not->toBeEmpty();

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'Photo + Album')
            && str_contains($job->content, 'Photo + Video + Album')
            && ! str_contains($job->content, 'PREWEDDING PACKAGE')
            && ! str_contains($job->content, 'WEDDING PACKAGE: 1.');
    });
});

test('short package follow up uses conversation history instead of falling back to generic triage', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Boleh jelaskan']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    LeadMemory::query()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'service_type' => 'wedding',
        'event_date' => '2026-12-30',
        'event_location' => 'Bekasi',
        'guest_count' => 250,
        'budget_min' => 3500000,
    ]);

    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'WEDDING PACKAGE',
        'sort_order' => 0,
        'content' => "1. Photo + Album\n- Durasi: 11 jam\n- Tim: 1 fotografer\n- Include: album leather cover, custom leather box, 50 foto edit\n- Harga: Rp 1.995.000\n\n2. Photo + Video + Album\n- Durasi: 11 jam\n- Tim: 1 fotografer + 2 videografer\n- Include: full video acara, album leather cover, custom box\n- Harga: Rp 4.250.000",
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'package_inquiry',
        'interpretation_source' => 'rules+llm',
        'filled_slots' => [
            'event_type' => 'wedding',
            'name' => null,
            'event_date' => '2026-12-30',
            'event_time_start' => null,
            'event_time_end' => null,
            'location' => 'Bekasi',
            'service_type' => 'wedding',
            'guest_count' => null,
            'budget' => null,
            'pricing_focus' => 'package_only',
            'package_interest' => null,
            'payment_topic' => null,
            'inquiry_fields' => [],
            'booking_fields' => [],
        ],
        'last_agent_message' => 'Untuk paket wedding, ada dua pilihan utama: Photo + Album dan Photo + Video + Album.',
        'last_answered_topic' => 'pricing',
        'next_best_action' => 'respond_to_user',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.45,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(1)
        ->and($conv->fresh()->state?->current_intent)->toBe('package_inquiry');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return str_contains($content, 'photo + album')
            && ! str_contains($content, 'mau kita fokus ke paket yang paling cocok')
            && ! str_contains($content, 'lagi cari layanan untuk acara apa');
    });
});

test('grounded package recommendation waits for basic client info before sending package list', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku mau lihat paket wedding']);
    $conv->update(['stage' => ConversationStage::PaymentDiscussion]);

    LeadMemory::query()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'service_type' => 'wedding',
        'event_date' => '2026-12-30',
        'event_location' => 'Bekasi',
    ]);

    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'WEDDING PACKAGE',
        'sort_order' => 0,
        'content' => "1. Photo + Album\n- Harga: Rp 1.995.000",
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-30',
            'location' => 'Bekasi',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.88,
        'current_stage' => 'payment_discussion',
        'suggested_next_stage' => 'payment_discussion',
        'missing_critical_fields' => [],
    ]));
    $llm->queueResponse('Siap, biar aku arahin paket yang paling pas, boleh tahu perkiraan jumlah tamunya berapa?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect($llm->calls)->toHaveCount(2);
});

test('generated reply is humanized when opener repeats previous assistant phrasing', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku mau lihat isi paketnya ka']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::FollowUp->value,
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Kemarin kita sudah sempat bahas paket wedding.',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));
    $llm->queueResponse('Siap, aku bantu jelaskan ya. Paket Gold biasanya paling sering dipilih untuk coverage yang seimbang.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_starts_with($job->content, 'Oke,')
            && str_contains($job->content, 'Paket Gold biasanya paling sering dipilih');
    });
});

test('quality filter replaces false promise before outbound send', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Harga paketnya berapa ka']);
    $conv->update(['stage' => ConversationStage::FollowUp]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
    ]));
    $llm->queueResponse('Harga kami pasti paling murah dan dijamin cocok buat semua kebutuhan.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return ! str_contains($content, 'pasti paling murah')
            && ! str_contains($content, 'dijamin cocok')
            && str_contains($content, 'harga');
    });
});

test('quality filter replaces repeated question before outbound send', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Bandung ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_question' => 'Untuk tanggal berapa ya acaranya?',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.7,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Baik kak, untuk tanggal berapa ya acaranya?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return strtolower($job->content) !== 'baik kak, untuk tanggal berapa ya acaranya?'
            && str_contains(strtolower($job->content), 'boleh info');
    });
});

test('quality filter repairs missing cta without replacing the whole reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Aku tertarik sama paket gold nih ka']);
    $conv->update(['stage' => ConversationStage::Closing]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::Closing->value,
        'next_best_action' => 'guide_to_booking',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.88,
    ]));
    $llm->queueResponse('Paket Gold biasanya paling cocok kalau kamu butuh coverage yang lengkap dan ritme acara yang padat.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'paket gold biasanya paling cocok')
            && str_contains($content, 'langkah booking')
            && ! str_contains($content, 'lebih perlu lihat harga, isi paket');
    });
});

test('non handoff response strips admin follow up language from llm output', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'aku lebih condong ke photo video sih ka']);
    $conv->update(['stage' => ConversationStage::NeedsDiscovery]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
    ]));
    $llm->queueResponse('Sip kak, paket Photo + Video memang cocok. Untuk detail harga lengkapnya, nanti tim admin kita akan segera menghubungi kamu ya supaya bisa jelasin semua dengan jelas.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'paket Photo + Video memang cocok')
            && ! str_contains(strtolower($job->content), 'admin')
            && ! str_contains(strtolower($job->content), 'menghubungi');
    });
});

test('quality filter duplicate reply rewrites into a non-looping contextual message', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Bisa dibantu jelasin?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Kalau sudah ada gambaran acara atau paket yang diincar, sebutkan aja, nanti aku lanjut dari situ.',
        'next_best_action' => 'respond_to_user',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.72,
    ]));
    $llm->queueResponse('Siap, aku bantu jelaskan ya. Kalau sudah ada gambaran acara atau paket yang diincar, sebutkan aja, nanti aku lanjut dari situ.');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    expect(HandoffRequest::count())->toBe(0)
        ->and($conv->fresh()->isHandoff())->toBeFalse();

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = mb_strtolower($job->content);

        return ! str_contains($content, 'gambaran acara atau paket yang diincar')
            && ! str_contains($content, 'admin kami lanjut bantu dari chat terakhir');
    });
});

test('explicit pricelist request without pricelist file creates handoff and queues admin fallback reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh minta pricelistnya kak?']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    $llm = new FakeLlmClient();

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    expect($llm->calls)->toHaveCount(0)
        ->and(HandoffRequest::count())->toBe(1)
        ->and(HandoffRequest::first()->status)->toBe(HandoffStatus::Pending)
        ->and(HandoffRequest::first()->reason_detail)->toBe('pricelist_missing');

    Queue::assertNotPushed(SendOutboundDocumentJob::class);
    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'pricelist PDF belum tersedia');
    });
});

test('valid handoff still allows admin acknowledgment copy', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh cek tanggal 12 desember available ga?']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'availability',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => true,
        'handoff_reason' => 'availability_check',
        'confidence' => 0.95,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains(strtolower($job->content), 'admin kami akan segera membalas');
    });
});

test('resolved handoff followed by ready_to_book sends booking form without repeat handoff', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'sort_order' => 1,
    ]);

    $existing = HandoffRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conv->id,
        'reason' => HandoffReason::CustomPackage,
        'status' => HandoffStatus::Resolved,
        'resolved_at' => now(),
    ]);

    $lead->update(['automation_paused' => false]);
    $conv->update(['status' => 'active']);
    $message->update(['content' => 'oke aku mau booking ya']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'ready_to_book',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => true,
        'handoff_reason' => 'ready_to_book',
        'confidence' => 0.95,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    expect(HandoffRequest::count())->toBe(1)
        ->and(HandoffRequest::first()->id)->toBe($existing->id)
        ->and($lead->fresh()->automation_paused)->toBeFalse();

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'kita lanjut ke booking')
            && str_contains($job->content, 'Nama Lengkap');
    });
});

test('booking form reply is stored to lead booking data and asks next field', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'sort_order' => 1,
    ]);
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Tanggal Acara',
        'field_key' => 'tanggal_acara',
        'sort_order' => 2,
    ]);

    $lead->update(['status' => \App\Modules\Leads\Enums\LeadStatus::Hot]);
    $message->update(['content' => 'Budi Santoso']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv);

    expect($llm->calls)->toHaveCount(1)
        ->and(LeadBookingData::where('lead_id', $lead->id)->where('form_type', FormType::Booking)->where('field_key', 'nama_lengkap')->value('field_value'))
        ->toBe('Budi Santoso');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains($job->content, 'aku catat')
            && str_contains($job->content, 'Tanggal Acara');
    });
});

test('transient classifier failure falls back to intent-aware reply when rules are clear', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'saya mau booking untuk tanggal 12 desember']);

    $llm = new class implements LlmClientInterface {
        public array $calls = [];

        public function complete(array $messages, array $options = []): \App\Modules\AgentCore\DTOs\LlmResponse
        {
            $this->calls[] = compact('messages', 'options');
            throw new \RuntimeException('Request rate limit has been exceeded.');
        }
    };

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) use ($lead, $conv) {
        return $job->agentId === $conv->whatsapp_agent_id
            && $job->to === $lead->phone_e164
            && str_contains(strtolower($job->content), 'booking')
            && ! str_contains(strtolower($job->content), 'pesanmu sudah kami terima');
    });
});

test('malformed classifier json falls back to intent-aware reply when rules are clear', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'saya mau booking untuk tanggal 12 desember']);

    $llm = new FakeLlmClient();
    $llm->queueResponse('{invalid-json');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) use ($lead, $conv) {
        return $job->agentId === $conv->whatsapp_agent_id
            && $job->to === $lead->phone_e164
            && str_contains(strtolower($job->content), 'booking')
            && ! str_contains(strtolower($job->content), 'pesanmu sudah kami terima');
    });

    $turn = \App\Modules\AgentCore\Models\ConversationTurnLog::where('message_id', $message->id)->first();

    expect($turn)->not->toBeNull()
        ->and($turn?->fallback_reason)->toBe('classifier_failed_using_rule')
        ->and($turn?->response_type)->toBe('ready_to_book');
});

test('invalid classifier with unclear rules sends safe fallback reply instead of silently exiting', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'kapan acaranya yah ka']);
    $conv->update(['stage' => ConversationStage::PackageRecommendation]);

    $llm = new FakeLlmClient();
    $llm->queueResponse('');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'aku bantu lanjut')
            && str_contains($content, 'langkah berikutnya');
    });

    $turn = \App\Modules\AgentCore\Models\ConversationTurnLog::where('message_id', $message->id)->first();
    $state = ConversationState::where('conversation_id', $conv->id)->first();

    expect($turn)->not->toBeNull()
        ->and($turn?->fallback_reason)->toBe('classifier_failed_no_rule_intent')
        ->and($turn?->response_type)->toBe('fallback')
        ->and($state?->last_tool_result_summary)->toBe('classifier_invalid_output_unclear_rules:general');
});

test('guardrail no-reply exit is explicitly logged with a reason', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message, 'agent' => $agent] = setupInboundScenario();
    $agent->update(['status' => \App\Modules\WhatsApp\Enums\AgentStatus::Disconnected]);

    $llm = new FakeLlmClient();
    $logPath = storage_path('logs/laravel.log');
    $before = file_exists($logPath) ? file_get_contents($logPath) : '';

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv->fresh());

    Queue::assertNothingPushed();
    $after = file_exists($logPath) ? file_get_contents($logPath) : '';

    expect($after)->not->toBe($before)
        ->and($after)->toContain('[AgentOrchestrator] No reply exit: guardrail_blocked')
        ->and($after)->toContain('guardrail_blocked');
});

test('runClassifier rejects malformed json response', function () {
    ['lead' => $lead, 'conv' => $conv] = setupInboundScenario();

    $llm = new FakeLlmClient();
    $llm->queueResponse('{invalid-json');

    buildOrchestrator($llm)->runClassifier($lead, $conv);
})->throws(\App\Modules\AgentCore\Exceptions\InvalidClassifierOutputException::class, 'Classifier output rejected');

test('runClassifier rejects empty json response', function () {
    ['lead' => $lead, 'conv' => $conv] = setupInboundScenario();

    $llm = new FakeLlmClient();
    $llm->queueResponse('   ');

    buildOrchestrator($llm)->runClassifier($lead, $conv);
})->throws(\App\Modules\AgentCore\Exceptions\InvalidClassifierOutputException::class, 'Classifier output rejected');

test('runClassifier rejects classifier payload with missing required fields', function () {
    ['lead' => $lead, 'conv' => $conv] = setupInboundScenario();

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
    ]));

    buildOrchestrator($llm)->runClassifier($lead, $conv);
})->throws(\App\Modules\AgentCore\Exceptions\InvalidClassifierOutputException::class, 'Classifier output rejected');

test('runClassifier accepts valid structured json response', function () {
    ['lead' => $lead, 'conv' => $conv] = setupInboundScenario();

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-12',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.87,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => ['location'],
    ]));

    $classifier = buildOrchestrator($llm)->runClassifier($lead, $conv);

    expect($classifier->intent)->toBe('tanya_harga')
        ->and($classifier->confidence)->toBe(0.87)
        ->and($classifier->currentStage)->toBe(ConversationStage::Qualification)
        ->and($classifier->suggestedNextStage)->toBe(ConversationStage::PackageRecommendation)
        ->and($classifier->missingCriticalFields)->toBe(['location']);
});

test('transient response failure without clear intent uses stage-aware fallback instead of generic receipt', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Bandung tanggal 12 desember']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    $llm = new class implements LlmClientInterface {
        public array $calls = [];

        public function complete(array $messages, array $options = []): \App\Modules\AgentCore\DTOs\LlmResponse
        {
            $this->calls[] = compact('messages', 'options');

            if (count($this->calls) === 1) {
                return new \App\Modules\AgentCore\DTOs\LlmResponse(json_encode([
                    'intent' => 'other',
                    'sentiment' => 'neutral',
                    'extracted_fields' => [],
                    'needs_handoff' => false,
                    'handoff_reason' => null,
                    'confidence' => 0.6,
                    'current_stage' => 'qualification',
                    'suggested_next_stage' => 'qualification',
                    'missing_critical_fields' => ['event_date'],
                ]), 0, 0, 0, 'fake');
            }

            throw new \RuntimeException('OpenAI connection timeout');
        }
    };

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'boleh info')
            && ! str_contains($content, 'pesanmu sudah kami terima')
            && ! str_contains($content, 'mau tanya apa');
    });
});

test('handleInbound updates structured conversation state across the turn', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Halo, acaranya di Bandung tanggal 12 Desember']);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [
            'location' => 'Bandung',
            'event_date' => '2026-12-12',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.92,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['service_type'],
    ]));
    $llm->queueResponse('Siap, aku catat ya. Untuk layanan yang kamu cari, lebih fokus foto, video, atau keduanya?');

    buildOrchestrator($llm)->handleInbound($message, $lead, $conv);

    $state = ConversationState::where('conversation_id', $conv->id)->first();

    expect($state)->not->toBeNull()
        ->and($state->current_intent)->toBe('unclear')
        ->and($state->intent_confidence)->toBe(0.92)
        ->and($state->interpretation_source)->toBe('llm+rules')
        ->and($state->current_stage)->toBe('qualification')
        ->and($state->lead_temperature)->toBe('cold')
        ->and($state->filled_slots['location'])->toBe('Bandung')
        ->and($state->filled_slots['event_date'])->toBe('2026-12-12')
        ->and($state->last_user_message)->toBe('Halo, acaranya di Bandung tanggal 12 Desember')
        ->and($state->last_agent_message)->toContain('lebih fokus foto')
        ->and($state->last_agent_question)->toContain('?');
});

test('handleInbound keeps replying when classifier returns natural-language event date', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'Tanggal 30 desember ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'qualification',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'event_date' => '30 desember',
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.88,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['location'],
    ]));
    $llm->queueResponse('Siap, tanggalnya sudah aku catat. Untuk lokasi acaranya di mana ya?');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains(strtolower($job->content), 'lokasi acaranya di mana');
    });

    $state = ConversationState::where('conversation_id', $conv->id)->first();

    expect($lead->fresh()->memory?->event_date?->toDateString())->toBe(now()->format('Y') . '-12-30')
        ->and($state)->not->toBeNull()
        ->and($state->filled_slots['event_date'])->toBe(now()->format('Y') . '-12-30');
});

test('asked_fields is not recorded when the predicted field is not actually asked in the final reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh bantu jelasin ya ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Paket Silver include 6 jam dokumentasi foto dan album basic.');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    expect($conv->fresh()->asked_fields)->toBe(['service_type'])
        ->and($conv->fresh()->next_expected_field)->toBe('event_date');
});

test('asked_fields is recorded only when the final reply actually asks that field', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh bantu jelasin ya ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Siap, aku bantu arahin ya. Boleh info tanggal acaranya dulu?');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    expect($conv->fresh()->asked_fields)->toBe(['service_type', 'event_date'])
        ->and($conv->fresh()->next_expected_field)->toBe('location');
});

test('asked_fields is not recorded when a rewrite removes the field question from the final reply', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh bantu jelasin ya ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_question' => 'Untuk tanggal berapa ya acaranya?',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.88,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Paket Silver include 6 jam dokumentasi foto. Untuk tanggal berapa ya acaranya?');

    buildOrchestrator($llm)->handleInbound($message, $lead->fresh(), $conv->fresh());

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return strtolower($job->content) === 'paket silver include 6 jam dokumentasi foto.';
    });

    expect($conv->fresh()->asked_fields)->toBe(['service_type'])
        ->and($conv->fresh()->next_expected_field)->toBe('event_date');
});

test('correct asked_fields recording prevents the next turn from repeating the same field question', function () {
    ['lead' => $lead, 'conv' => $conv, 'message' => $message] = setupInboundScenario();
    $message->update(['content' => 'boleh bantu jelasin ya ka']);
    $conv->update([
        'stage' => ConversationStage::Qualification,
        'asked_fields' => ['service_type'],
        'next_expected_field' => 'event_date',
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'positive',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['event_date'],
    ]));
    $llm->queueResponse('Siap, aku bantu arahin ya. Boleh info tanggal acaranya dulu?');

    $orchestrator = buildOrchestrator($llm);
    $orchestrator->handleInbound($message, $lead->fresh(), $conv->fresh());

    $followUpMessage = Message::factory()->create([
        'tenant_id' => $lead->tenant_id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'masih lihat paketnya dulu ya',
    ]);

    $llm->queueResponse(json_encode([
        'intent' => 'other',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.7,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'qualification',
        'missing_critical_fields' => ['location'],
    ]));
    $llm->queueResponse('Yang paling ingin kamu cari tahu apa, Kak?');

    $orchestrator->handleInbound($followUpMessage, $lead->fresh(), $conv->fresh());

    expect($conv->fresh()->asked_fields)->toBe(['service_type', 'event_date', 'location']);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        $content = strtolower($job->content);

        return str_contains($content, 'lokasi acara')
            && ! str_contains($content, 'tanggal acara');
    });
});
