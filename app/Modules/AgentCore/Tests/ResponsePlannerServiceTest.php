<?php

use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\AgentCore\Services\ResponsePlannerService;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;

function makeResponsePlanner(): ResponsePlannerService
{
    $leadMemoryService = new LeadMemoryService();
    $bookingDataService = new LeadBookingDataService();
    $closingPolicyService = new ClosingPolicyService(
        $leadMemoryService,
        $bookingDataService,
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );

    return new ResponsePlannerService($closingPolicyService);
}

test('response planner prioritizes explicit pricing focus before anything else', function () {
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
        'current_intent' => 'price_inquiry',
        'next_best_action' => 'respond_to_user',
        'filled_slots' => [
            'pricing_focus' => 'price_and_package',
        ],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['answer_mode'])->toBe('answer_pricing')
        ->and($plan['answer_focus'])->toBe('pricing_focus:price_and_package')
        ->and($plan['user_focus_rule'])->toContain('Jawab harga dan isi paket dulu')
        ->and($plan['banned_moves'])->toContain('jangan ulang triage harga vs isi paket');
});

test('response planner prefers grounded package answer for package-only inquiries', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PackageRecommendation)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'package_inquiry',
        'next_best_action' => 'respond_to_user',
        'filled_slots' => [
            'pricing_focus' => 'package_only',
        ],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['answer_mode'])->toBe('grounded_package_answer')
        ->and($plan['answer_focus'])->toBe('pricing_focus:package_only')
        ->and($plan['banned_moves'])->toContain('jangan suntik topik DP, pelunasan, atau booking kalau user masih fokus ke paket/harga');
});

test('response planner turns next ask action into a single-field collection plan', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Qualification)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'unclear',
        'next_best_action' => 'ask_event_date',
        'filled_slots' => [],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['ask_mode'])->toBe('ask_single_missing_field')
        ->and($plan['ask_field'])->toBe('event_date')
        ->and($plan['answer_focus'])->toBe('missing_field:event_date');
});

test('response planner uses recommendation policy for cocok prompt and asks one high-value follow up', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'package_inquiry',
        'last_user_message' => 'Kira-kira yang cocok buat saya yang mana ya?',
        'next_best_action' => 'guide_to_booking',
        'filled_slots' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-12',
            'location' => 'Bandung',
            'budget' => null,
            'guest_count' => null,
        ],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['answer_mode'])->toBe('recommend_package')
        ->and($plan['answer_focus'])->toBe('package_recommendation_then_probe:budget')
        ->and($plan['ask_mode'])->toBe('ask_single_missing_field')
        ->and($plan['ask_field'])->toBe('budget')
        ->and($plan['user_focus_rule'])->toContain('Beri rekomendasi awal yang paling masuk akal');
});

test('response planner suppresses payment framing when package intent reappears in payment stage', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'package_inquiry',
        'last_user_message' => 'Aku mau tanya paket wedding yang cocok',
        'next_best_action' => 'guide_to_booking',
        'filled_slots' => [
            'pricing_focus' => 'package_only',
            'payment_topic' => 'down_payment',
        ],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['answer_mode'])->toBe('recommend_package')
        ->and($plan['answer_focus'])->toBe('package_recommendation_then_probe:budget')
        ->and($plan['ask_field'])->toBe('budget')
        ->and($plan['ask_mode'])->toBe('ask_single_missing_field')
        ->and($plan['next_best_action'])->toBe('respond_to_user')
        ->and($plan['cta_level'])->toBe('none')
        ->and($plan['cta_style'])->toContain('discovery baru')
        ->and($plan['user_focus_rule'])->toContain('Beri rekomendasi awal yang paling masuk akal');
});

test('response planner suppresses stale ask action for price inquiry in closing stage', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Closing)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'current_intent' => 'price_inquiry',
        'last_user_message' => 'Harga paket wedding berapa ya?',
        'next_best_action' => 'ask_guest_count',
        'filled_slots' => [
            'pricing_focus' => 'price_only',
        ],
    ]);

    $plan = makeResponsePlanner()->resolve($conv, $lead);

    expect($plan['answer_mode'])->toBe('answer_pricing')
        ->and($plan['answer_focus'])->toBe('pricing_focus:price_only')
        ->and($plan['ask_field'])->toBeNull()
        ->and($plan['ask_mode'])->toBe('no_question_unless_needed')
        ->and($plan['next_best_action'])->toBe('respond_to_user');
});
