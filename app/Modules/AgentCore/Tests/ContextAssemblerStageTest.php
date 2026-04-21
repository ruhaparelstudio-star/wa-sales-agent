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
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function buildStageAwareAssembler(): ContextAssembler
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

test('context block contains TONE PROFILE and CONVERSATION STATE sections', function () {
    $tenant = Tenant::factory()->create([
        'tone_profile' => [
            'language_primary' => 'id',
            'formality' => 'semi_casual',
            'persona_style' => 'warm',
            'forbidden_phrases' => ['pasti bisa'],
        ],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);
    $conv->forceFill(['asked_fields' => ['name']])->save();

    $messages = buildStageAwareAssembler()->assemble($lead, $conv, LlmMode::Response, 'tanya_paket');
    $block    = $messages[1]['content'];

    expect($block)
        ->toContain('[TONE PROFILE]')
        ->toContain('formality: semi_casual')
        ->toContain('persona_style: warm')
        ->toContain('[STRUCTURED STATE]')
        ->toContain('[CONVERSATION STATE]')
        ->toContain('stage: qualification')
        ->toContain('already_asked: name')
        ->toContain('next_expected_field');
});

test('summary mode includes persisted structured state for downstream summary generation', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);
    $lead->memory()->create([
        'tenant_id' => $tenant->id,
        'preferred_packages' => ['gold'],
        'custom_fields' => [
            'pricing_focus' => 'price_and_package',
            'payment_topic' => 'down_payment',
            'event_time_start' => '10:00',
            'event_time_end' => '12:00',
        ],
    ]);

    $messages = buildStageAwareAssembler()->assemble($lead, $conv, LlmMode::Summary);
    $block    = $messages[1]['content'];

    expect($block)
        ->toContain('[RECENT MESSAGES]')
        ->toContain('[LEAD MEMORY]')
        ->toContain('[STRUCTURED STATE]')
        ->toContain('pricing_focus: price_and_package')
        ->toContain('payment_topic: down_payment')
        ->not->toContain('[TONE PROFILE]');
});

test('next_expected_field skips fields already asked', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $block = buildStageAwareAssembler()->assemble($lead, $conv, LlmMode::Response, 'greeting')[1]['content'];

    expect($block)->toContain('next_expected_field: event_date');
});
