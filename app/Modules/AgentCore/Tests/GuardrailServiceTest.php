<?php

use App\Modules\AgentCore\Services\GuardrailService;
use App\Modules\AgentCore\Services\RiskPolicyService;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Models\Lead;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Services\AgentSlotPolicyService;
use App\Modules\Subscription\Services\SubscriptionEnforcementService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeGuardrail(): GuardrailService
{
    $subService  = new SubscriptionService();
    $slotService = app(AgentSlotPolicyService::class);
    $enforcement = new SubscriptionEnforcementService($subService, $slotService);

    return new GuardrailService($enforcement, new RiskPolicyService());
}

function makeConvWithActiveSub(array $tenantOverrides = []): array
{
    $tenant = Tenant::factory()->create($tenantOverrides);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id]);
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead  = Lead::factory()->create(['tenant_id' => $tenant->id, 'whatsapp_agent_id' => $agent->id]);
    $conv  = Conversation::factory()->active()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    return compact('tenant', 'agent', 'lead', 'conv');
}

test('paused lead is blocked', function () {
    ['lead' => $lead, 'conv' => $conv] = makeConvWithActiveSub();
    $lead->update(['automation_paused' => true]);

    $result = makeGuardrail()->check($lead->fresh(), $conv);

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toBe('lead_automation_paused');
});

test('human takeover conversation is blocked', function () {
    ['lead' => $lead, 'conv' => $conv] = makeConvWithActiveSub();
    $conv->update(['is_human_takeover' => true]);

    $result = makeGuardrail()->check($lead->fresh(), $conv->fresh());

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toBe('conversation_human_takeover');
});

test('expired subscription is blocked', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->expired()->create(['tenant_id' => $tenant->id]);
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead  = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv  = Conversation::factory()->active()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $result = makeGuardrail()->check($lead, $conv);

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toStartWith('subscription_blocked');
});

test('disconnected agent is blocked', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id]);
    $agent = WhatsAppAgent::factory()->disconnected()->create(['tenant_id' => $tenant->id]);
    $lead  = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv  = Conversation::factory()->active()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $result = makeGuardrail()->check($lead, $conv);

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toBe('agent_not_connected');
});

test('quiet hours blocks outbound', function () {
    $now = now();
    $start = $now->copy()->subMinute()->format('H:i');
    $end   = $now->copy()->addHour()->format('H:i');

    ['lead' => $lead, 'conv' => $conv] = makeConvWithActiveSub([
        'settings' => [
            'quiet_hours_start' => $start,
            'quiet_hours_end'   => $end,
        ],
    ]);

    $result = makeGuardrail()->check($lead, $conv);

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toBe('quiet_hours');
});

test('allowed when all checks pass', function () {
    ['lead' => $lead, 'conv' => $conv] = makeConvWithActiveSub([
        'settings' => [
            'quiet_hours_start' => '03:00',
            'quiet_hours_end'   => '03:05',
        ],
    ]);

    $result = makeGuardrail()->check($lead, $conv);

    expect($result->blocked)->toBeFalse();
});

test('high risk score is blocked', function () {
    ['lead' => $lead, 'conv' => $conv] = makeConvWithActiveSub([
        'settings' => ['quiet_hours_start' => '03:00', 'quiet_hours_end' => '03:05'],
    ]);
    $lead->update(['risk_score' => 85]);

    $result = makeGuardrail()->check($lead->fresh(), $conv);

    expect($result->blocked)->toBeTrue()
        ->and($result->reason)->toBe('high_risk_score');
});
