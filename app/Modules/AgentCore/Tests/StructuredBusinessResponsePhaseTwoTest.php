<?php

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\Handlers\BookingFieldReplyHandler;
use App\Modules\AgentCore\Handlers\PackageDetailsInquiryHandler;
use App\Modules\AgentCore\Handlers\PricelistInquiryHandler;
use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\AgentCore\Services\BusinessPayloadResponder;
use App\Modules\AgentCore\Services\ContextAwareFallbackBuilder;
use App\Modules\AgentCore\Services\FallbackGuardService;
use App\Modules\AgentCore\Services\QualityFilterService;
use App\Modules\AgentCore\Tests\Support\FakeLlmClient;
use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Jobs\SendOutboundDocumentJob;
use App\Modules\WhatsApp\Jobs\SendOutboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    Storage::fake('local');
});

function buildStructuredPayloadOrchestrator(LlmClientInterface $llm): AgentOrchestrator
{
    foreach ([
        AgentOrchestrator::class,
        \App\Modules\AgentCore\Dispatch\ActionDispatcher::class,
        BookingFieldReplyHandler::class,
        PackageDetailsInquiryHandler::class,
        PricelistInquiryHandler::class,
        BusinessPayloadResponder::class,
        ContextAwareFallbackBuilder::class,
        FallbackGuardService::class,
        QualityFilterService::class,
        \App\Modules\AgentCore\Services\ResponsePlannerService::class,
        \App\Modules\Conversations\Services\ConversationStateService::class,
        \App\Modules\Knowledge\Services\PricelistService::class,
    ] as $class) {
        app()->forgetInstance($class);
    }

    app()->instance(LlmClientInterface::class, $llm);

    return app(AgentOrchestrator::class);
}

function setupStructuredPayloadScenario(): array
{
    $tenant = Tenant::factory()->create();
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id]);
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
    ]);
    $conversation = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'halo, paketnya ada apa aja?',
    ]);

    return compact('tenant', 'agent', 'lead', 'conversation', 'message');
}

test('pricelist inquiry handler returns structured payload and responder renders follow up text', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $payload = app(PricelistInquiryHandler::class)->buildPayload(
        new \App\Modules\AgentCore\DTOs\PricelistInquiryHandlerInput(
            lead: $lead,
            conversation: $conversation,
            message: $message,
            intent: 'tanya_harga',
        )
    );
    $rendered = app(BusinessPayloadResponder::class)->render($payload);

    expect($payload->toArray()['payload_type'])->toBe('pricelist_info')
        ->and($payload->toArray()['action'])->toBe('reply_with_pricelist')
        ->and($payload->data['delivery_status'])->toBe('ready_to_send')
        ->and($payload->data['pricelist_available'])->toBeTrue()
        ->and($payload->data['next_best_action'])->toBe('share_pricelist')
        ->and($payload->data['tool_result_summary'])->toBe('pricelist_pdf_queued')
        ->and($payload->responseRules)->toMatchArray([
            'must_answer_latest_question_first' => true,
            'must_not_invent_price' => true,
            'must_not_invent_availability' => true,
            'must_not_promise_followup_without_action' => true,
        ])
        ->and($payload->data['relative_path'])->toContain("tenants/{$tenant->id}/pricelists/")
        ->and($rendered->deliveryMode)->toBe('document_follow_up')
        ->and($rendered->followUpText)->toContain('Pricelist PDF-nya sudah aku kirim')
        ->and($rendered->followUpText)->toContain('tinggal bilang aja');
});

test('package details handler returns enough structured facts and responder renders without extra business decisions', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $message->update(['content' => 'aku mau lihat isi paket weddingnya']);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'filled_slots' => [
            'event_type' => 'wedding',
            'package_interest' => null,
        ],
        'last_agent_message' => 'Untuk paket wedding, pilihannya ada beberapa ya.',
    ]);

    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'WEDDING PACKAGE',
        'content' => "1. Wedding Photo Only\n- Durasi: 8 jam\n- Tim: 1 fotografer\n- Include: 50 edit foto, album\n- Harga: Rp 1.995.000\n\n2. Wedding Photo + Video\n- Durasi: 11 jam\n- Tim: 1 fotografer + 2 videografer\n- Include: full video acara, album leather cover\n- Harga: Rp 4.250.000",
    ]);

    $payload = app(PackageDetailsInquiryHandler::class)->buildPayload(
        new \App\Modules\AgentCore\DTOs\PackageDetailsHandlerInput(
            lead: $lead,
            conversation: $conversation,
            message: $message->fresh(),
            intent: 'tanya_paket',
        )
    );
    $rendered = app(BusinessPayloadResponder::class)->render($payload);

    expect($payload)->not->toBeNull()
        ->and($payload?->toArray()['payload_type'])->toBe('package_details')
        ->and($payload?->toArray()['action'])->toBe('reply_with_grounded_package')
        ->and($payload?->data['presentation_mode'])->toBe('structured_variants')
        ->and($payload?->data['scope'])->toBe('paket wedding')
        ->and($payload?->data['next_best_action'])->toBe('respond_to_user')
        ->and($payload?->data['tool_result_summary'])->toBe('grounded_package_answer')
        ->and($payload?->data['variants'][0]['name'] ?? null)->toBe('Wedding Photo Only')
        ->and($payload?->data['variants'][0]['price'] ?? null)->toBe('Rp 1.995.000')
        ->and($rendered->text)->toContain('Untuk paket wedding, pilihan yang paling relevan ada 2:')
        ->and($rendered->text)->toContain('Wedding Photo Only')
        ->and($rendered->text)->toContain('harga Rp 1.995.000')
        ->and($rendered->text)->toContain('Wedding Photo + Video');
});

test('booking field reply handler returns structured payload and responder only realizes next prompt', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $lead->update(['status' => LeadStatus::Hot]);
    $message->update(['content' => 'Budi Santoso']);

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
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

    $payload = app(BookingFieldReplyHandler::class)->buildPayload(
        new \App\Modules\AgentCore\DTOs\BookingFieldReplyHandlerInput(
            lead: $lead,
            conversation: $conversation,
            message: $message->fresh(),
        )
    );
    $rendered = app(BusinessPayloadResponder::class)->render($payload);

    expect($payload)->not->toBeNull()
        ->and($payload?->toArray()['payload_type'])->toBe('booking_field_clarification')
        ->and($payload?->toArray()['action'])->toBe('ask_for_booking_field')
        ->and($payload?->data['saved_field'])->toMatchArray([
            'key' => 'nama_lengkap',
            'label' => 'Nama Lengkap',
            'value' => 'Budi Santoso',
        ])
        ->and($payload?->data['next_field'])->toMatchArray([
            'key' => 'tanggal_acara',
            'label' => 'Tanggal Acara',
        ])
        ->and($payload?->data['is_complete'])->toBeFalse()
        ->and(LeadBookingData::where('lead_id', $lead->id)->where('field_key', 'nama_lengkap')->value('field_value'))->toBe('Budi Santoso')
        ->and($rendered->text)->toBe('Siap, aku catat ya. Lanjut, boleh info Tanggal Acara?');
});

test('booking field reply with invalid date does not persist and asks for a valid value', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $lead->update(['status' => LeadStatus::Hot]);
    $message->update(['content' => 'besok pagi-pagi aja']);

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Tanggal Acara',
        'field_key' => 'tanggal_acara',
        'field_type' => BookingFieldType::Date,
        'sort_order' => 1,
    ]);

    $payload = app(BookingFieldReplyHandler::class)->buildPayload(
        new \App\Modules\AgentCore\DTOs\BookingFieldReplyHandlerInput(
            lead: $lead,
            conversation: $conversation,
            message: $message->fresh(),
        )
    );
    $rendered = app(BusinessPayloadResponder::class)->render($payload);

    expect($payload)->not->toBeNull()
        ->and($payload?->data['saved_field'])->toBeNull()
        ->and($payload?->data['invalid_field'])->toMatchArray([
            'key' => 'tanggal_acara',
            'label' => 'Tanggal Acara',
            'raw_value' => 'besok pagi-pagi aja',
        ])
        ->and($payload?->data['is_complete'])->toBeFalse()
        ->and(LeadBookingData::where('lead_id', $lead->id)->where('field_key', 'tanggal_acara')->exists())->toBeFalse()
        ->and($rendered->text)->toContain('Tanggal Acara');
});

test('direct pricelist inquiry keeps current visible behavior while using structured payload path', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $message->update(['content' => 'boleh minta pricelistnya kak?']);

    Storage::disk('local')->put("tenants/{$tenant->id}/pricelists/latest.pdf", 'dummy pdf');

    $llm = new FakeLlmClient();

    buildStructuredPayloadOrchestrator($llm)->handleInbound($message->fresh(), $lead->fresh(), $conversation->fresh());

    expect($llm->calls)->toHaveCount(0);

    Queue::assertPushed(SendOutboundDocumentJob::class, function ($job) {
        return str_contains((string) $job->followUpText, 'Pricelist PDF-nya sudah aku kirim')
            && str_contains((string) $job->followUpText, 'tinggal bilang aja');
    });
});

test('package details inquiry now uses payload driven responder and preserves expected reply shape', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $message->update(['content' => 'aku mau lihat isi paket weddingnya']);
    $conversation->update([
        'stage' => ConversationStage::PackageRecommendation,
        'asked_fields' => ['event_date', 'location', 'guest_count', 'budget'],
    ]);

    LeadMemory::query()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
        'guest_count' => 300,
        'budget_min' => 5000000,
        'service_type' => 'wedding',
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'current_stage' => ConversationStage::PackageRecommendation->value,
        'current_intent' => 'package_inquiry',
        'filled_slots' => [
            'event_type' => 'wedding',
            'package_interest' => null,
            'pricing_focus' => null,
        ],
        'last_answered_topic' => 'pricing',
    ]);

    KnowledgeItem::factory()->package()->forTenant($tenant)->create([
        'title' => 'WEDDING PACKAGE',
        'content' => "1. Wedding Photo Only\n- Durasi: 8 jam\n- Tim: 1 fotografer\n- Include: 50 edit foto, album\n- Harga: Rp 1.995.000\n\n2. Wedding Photo + Video\n- Durasi: 11 jam\n- Tim: 1 fotografer + 2 videografer\n- Include: full video acara, album leather cover\n- Harga: Rp 4.250.000",
    ]);

    $llm = new FakeLlmClient();
    $llm->queueResponse(json_encode([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.93,
        'current_stage' => 'package_recommendation',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => [],
    ]));

    buildStructuredPayloadOrchestrator($llm)->handleInbound($message->fresh(), $lead->fresh(), $conversation->fresh());

    expect($llm->calls)->toHaveCount(1);

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains((string) $job->content, 'Untuk paket wedding, pilihan yang paling relevan ada 2:')
            && str_contains((string) $job->content, 'Wedding Photo Only')
            && str_contains((string) $job->content, 'Wedding Photo + Video');
    });
});

test('booking field reply still stores booking data and asks the next field through payload responder', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message] = setupStructuredPayloadScenario();
    $lead->update(['status' => LeadStatus::Hot]);
    $message->update(['content' => 'Budi Santoso']);

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
        'intent' => 'other',
        'sentiment' => 'positive',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]));

    buildStructuredPayloadOrchestrator($llm)->handleInbound($message->fresh(), $lead->fresh(), $conversation->fresh());

    expect($llm->calls)->toHaveCount(1)
        ->and(LeadBookingData::where('lead_id', $lead->id)->where('field_key', 'nama_lengkap')->value('field_value'))->toBe('Budi Santoso');

    Queue::assertPushed(SendOutboundMessageJob::class, function ($job) {
        return str_contains((string) $job->content, 'aku catat')
            && str_contains((string) $job->content, 'Tanggal Acara');
    });
});
