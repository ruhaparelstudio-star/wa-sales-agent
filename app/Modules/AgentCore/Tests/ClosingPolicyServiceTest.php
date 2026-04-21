<?php

use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\Services\ClosingPolicyService;
use App\Modules\AgentCore\Services\CtaSuggestionService;
use App\Modules\AgentCore\Services\LeadReadinessScorer;
use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Tenancy\Models\Tenant;

function makeClosingPolicyService(): ClosingPolicyService
{
    return new ClosingPolicyService(
        new LeadMemoryService(),
        new LeadBookingDataService(),
        new LeadReadinessScorer(),
        new CtaSuggestionService(),
    );
}

test('payment inquiry in payment discussion gets payment-first CTA guidance', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->interested()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PaymentDiscussion)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'name' => 'Ayu',
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
        'budget_min' => 18000000,
    ]);

    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->required()->create([
        'label' => 'Nama Lengkap',
        'field_key' => 'nama_lengkap',
        'field_type' => BookingFieldType::Text,
        'sort_order' => 1,
    ]);

    $policy = makeClosingPolicyService()->resolve(
        $conv,
        $lead->fresh(),
        null,
        new InterpretationResult(
            canonicalIntent: 'payment_inquiry',
            legacyIntent: 'payment_inquiry',
            slots: ['payment_topic' => 'down_payment'],
            confidence: 0.93,
            source: 'rules',
        ),
    );

    expect($policy['answer_priority'])->toBe('answer_payment_question_first')
        ->and($policy['cta_level'])->toBeIn(['medium', 'hard'])
        ->and($policy['next_best_action'])->toStartWith('answer_payment_then_');
});

test('closing stage with missing booking field produces hard CTA focused on next field', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::Closing)->create([
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

    $policy = makeClosingPolicyService()->resolve(
        $conv,
        $lead->fresh(),
        null,
        new InterpretationResult(
            canonicalIntent: 'booking_intent',
            legacyIntent: 'ready_to_book',
            slots: [],
            confidence: 0.95,
            source: 'rules',
        ),
    );

    expect($policy['cta_level'])->toBe('hard')
        ->and($policy['booking_field_focus'])->toBe('nama_lengkap')
        ->and($policy['next_best_action'])->toBe('collect_nama_lengkap');
});

test('package recommendation without strong buying signals stays soft at most', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->qualified()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->atStage(ConversationStage::PackageRecommendation)->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    app(LeadMemoryService::class)->upsert($lead, [
        'service_type' => 'wedding',
        'event_date' => '2026-12-12',
        'event_location' => 'Bandung',
    ]);

    $policy = makeClosingPolicyService()->resolve($conv, $lead->fresh());

    expect($policy['cta_level'])->toBeIn(['none', 'soft'])
        ->and($policy['readiness_label'])->toBeIn(['warm', 'hot']);
});
