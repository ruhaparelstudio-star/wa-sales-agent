<?php

use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\ContextAssembler;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\AgentCore\Services\PromptBuilder;
use App\Modules\AgentCore\Services\ResponsePlannerService;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Actions\TransitionConversationStageAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Knowledge\Enums\KnowledgeType;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;


beforeEach(fn () => Cache::flush());

function makeContextAssembler(): ContextAssembler
{
    $leadMemoryService = new LeadMemoryService();
    $bookingDataService = new LeadBookingDataService();
    $stageService = new ConversationStageService(new TransitionConversationStageAction());
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        $bookingDataService,
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );

    return new ContextAssembler(
        new PromptBuilder(),
        $leadMemoryService,
        new KnowledgeRetrievalService(),
        new ConversationService(),
        new ConversationSummaryService(),
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
}

test('recent messages section includes max 6 messages', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    foreach (range(1, 10) as $i) {
        Message::factory()->create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conv->id,
            'lead_id'         => $lead->id,
            'content'         => "msg-{$i}",
            'created_at'      => now()->subMinutes(10 - $i),
        ]);
    }

    $messages = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_paket');
    $contextBlock = $messages[1]['content'];

    // Messages 1-4 (oldest) should be dropped; 5-10 retained in RECENT MESSAGES.
    expect($contextBlock)->toContain("[RECENT MESSAGES]\nuser: msg-5")
        ->and($contextBlock)->toContain('msg-10')
        ->and($contextBlock)->not->toContain('msg-1:')
        ->and($contextBlock)->not->toContain('msg-4:');
});

test('knowledge section does not include other tenant knowledge', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    KnowledgeItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'title'     => 'Paket Silver Tenant A',
        'type'      => KnowledgeType::Package,
        'is_active' => true,
    ]);
    KnowledgeItem::factory()->create([
        'tenant_id' => $tenantB->id,
        'title'     => 'Paket Gold Tenant B',
        'type'      => KnowledgeType::Package,
        'is_active' => true,
    ]);

    $lead = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $conv = Conversation::factory()->active()->create([
        'tenant_id' => $tenantA->id,
        'lead_id'   => $lead->id,
    ]);

    $messages = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_paket');
    $block = $messages[1]['content'];

    expect($block)->toContain('Paket Silver Tenant A')
        ->and($block)->not->toContain('Paket Gold Tenant B');
});

test('total context character budget stays within limit', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    // Seed an abusive amount of content that would blow the budget if unchecked.
    foreach (range(1, 20) as $i) {
        Message::factory()->create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conv->id,
            'lead_id'         => $lead->id,
            'content'         => str_repeat('long ', 500),
            'created_at'      => now()->subMinutes(30 - $i),
        ]);
    }
    foreach (range(1, 6) as $i) {
        KnowledgeItem::factory()->create([
            'tenant_id' => $tenant->id,
            'title'     => "item-{$i}",
            'content'   => str_repeat('x', 5000),
            'type'      => KnowledgeType::Faq,
            'is_active' => true,
        ]);
    }

    $messages = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_harga');

    expect(strlen($messages[1]['content']))
        ->toBeLessThanOrEqual(ContextAssembler::TOTAL_CHAR_BUDGET + 20); // small slack for truncation marker
});

test('system prompt differs per mode', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    $classifier = makeContextAssembler()->assemble($lead, $conv, LlmMode::Classifier)[0]['content'];
    $response   = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response)[0]['content'];
    $followUp   = makeContextAssembler()->assemble($lead, $conv, LlmMode::FollowUp)[0]['content'];
    $summary    = makeContextAssembler()->assemble($lead, $conv, LlmMode::Summary)[0]['content'];
    $evaluation = makeContextAssembler()->assemble($lead, $conv, LlmMode::Evaluation)[0]['content'];

    expect($classifier)->toContain('classifier')
        ->and($response)->toContain('sales agent')
        ->and($followUp)->toContain('sedang mengirim follow-up')
        ->and($summary)->toContain('SUMMARY:')
        ->and($evaluation)->toContain('mengevaluasi kualitas respons');
});

test('context assembly is mode specific instead of one generic block', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    KnowledgeItem::factory()->create([
        'tenant_id' => $tenant->id,
        'title'     => 'Paket Diamond',
        'type'      => KnowledgeType::Package,
        'is_active' => true,
    ]);
    Message::factory()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
        'direction'       => MessageDirection::Inbound,
        'content'         => 'Ada paket untuk wedding?',
        'created_at'      => now(),
    ]);

    $classifierBlock = makeContextAssembler()->assemble($lead, $conv, LlmMode::Classifier)[1]['content'];
    $responseBlock   = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_paket')[1]['content'];
    $followUpBlock   = makeContextAssembler()->assemble($lead, $conv, LlmMode::FollowUp)[1]['content'];

    expect($classifierBlock)
        ->toContain('[LATEST USER ASK]')
        ->toContain('[STRUCTURED STATE]')
        ->not->toContain('[KNOWLEDGE]')
        ->not->toContain('[TONE PROFILE]');

    expect($responseBlock)
        ->toContain('[KNOWLEDGE]')
        ->toContain('[TONE PROFILE]')
        ->toContain('[LATEST USER ASK]');

    expect($followUpBlock)
        ->toContain('[CONVERSATION SUMMARY]')
        ->toContain('[LATEST USER ASK]')
        ->not->toContain('[KNOWLEDGE]');
});

test('message direction renders as user vs assistant roles', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Message::factory()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
        'direction'       => MessageDirection::Inbound,
        'content'         => 'halo mau tanya',
        'created_at'      => now()->subMinute(),
    ]);
    Message::factory()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
        'direction'       => MessageDirection::Outbound,
        'content'         => 'halo kak, boleh',
        'created_at'      => now(),
    ]);

    $block = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response)[1]['content'];

    expect($block)->toContain('user: halo mau tanya')
        ->and($block)->toContain('assistant: halo kak, boleh');
});

test('structured state section is included for response mode', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    $block = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response)[1]['content'];

    expect($block)
        ->toContain('[STRUCTURED STATE]')
        ->toContain('lead_temperature: warm')
        ->toContain('next_best_action')
        ->toContain('[CLOSING POLICY]')
        ->toContain('cta_level');
});

test('response context includes active pricing focus instructions when state has pricing focus', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'filled_slots' => [
            'pricing_focus' => 'price_and_package',
        ],
    ]);

    $block = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_harga')[1]['content'];

    expect($block)
        ->toContain('[ACTIVE USER FOCUS]')
        ->toContain('pricing_focus: price_and_package')
        ->toContain('Jawab fokus harga dan isi paket user dulu')
        ->toContain('Jika ACTIVE USER FOCUS ada, jawab fokus itu dulu sebelum menggali hal lain.');
});

test('response context includes explicit response plan section', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'price_inquiry',
        'next_best_action' => 'ask_event_date',
        'filled_slots' => [
            'pricing_focus' => 'price_only',
        ],
    ]);

    $block = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_harga')[1]['content'];

    expect($block)
        ->toContain('[RESPONSE PLAN]')
        ->toContain('answer_mode: answer_pricing')
        ->toContain('answer_focus: pricing_focus:price_only')
        ->toContain('ask_field: event_date')
        ->toContain('user_focus_rule: Jawab harga dulu');
});

test('response context suppresses stale ask field for pricing in payment stage', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'price_inquiry',
        'next_best_action' => 'ask_event_date',
        'filled_slots' => [
            'pricing_focus' => 'price_only',
        ],
    ]);

    $block = makeContextAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_harga')[1]['content'];

    expect($block)
        ->toContain('answer_mode: answer_pricing')
        ->toContain('answer_focus: pricing_focus:price_only')
        ->toContain('ask_field: (none)')
        ->toContain('next_best_action: respond_to_user')
        ->toContain('user_focus_rule: Jawab kebutuhan paket atau harga user dulu tanpa minta data discovery baru.');
});
