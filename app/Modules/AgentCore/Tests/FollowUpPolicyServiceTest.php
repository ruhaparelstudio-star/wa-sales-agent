<?php

use App\Modules\AgentCore\Services\FollowUpPolicyService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeFollowUpService(): FollowUpPolicyService
{
    return new FollowUpPolicyService();
}

test('follow_up_count >= 2 → ineligible', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'last_message_at' => now()->subDays(3),
    ]);
    LeadMemory::create([
        'lead_id' => $lead->id,
        'tenant_id' => $tenant->id,
        'custom_fields' => [
            'follow_up_count' => 2,
            'follow_up_1_sent_at' => now()->subDays(3)->toIso8601String(),
            'follow_up_2_sent_at' => now()->subDays(1)->toIso8601String(),
        ],
    ]);

    $result = makeFollowUpService()->canSendFollowUp($lead->fresh());

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe('max_follow_up_reached');
});

test('FU-1 belum 18 jam → ineligible', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'last_message_at' => now()->subHours(10),
    ]);

    $result = makeFollowUpService()->canSendFollowUp($lead);

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe('fu1_cooldown_not_elapsed');
});

test('FU-1 sudah lebih dari 18 jam → eligible sebagai FU-1', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'last_message_at' => now()->subHours(20),
    ]);

    $result = makeFollowUpService()->canSendFollowUp($lead);

    expect($result->eligible)->toBeTrue()
        ->and($result->nextFollowUpNumber)->toBe(1);
});

test('FU-2 belum 48 jam setelah FU-1 → ineligible', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'last_message_at' => now()->subDays(2),
    ]);
    LeadMemory::create([
        'lead_id' => $lead->id,
        'tenant_id' => $tenant->id,
        'custom_fields' => [
            'follow_up_count' => 1,
            'follow_up_1_sent_at' => now()->subHours(20)->toIso8601String(),
        ],
    ]);

    $result = makeFollowUpService()->canSendFollowUp($lead->fresh());

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe('fu2_cooldown_not_elapsed');
});

test('paused lead → ineligible', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->paused()->create(['tenant_id' => $tenant->id]);

    $result = makeFollowUpService()->canSendFollowUp($lead);

    expect($result->eligible)->toBeFalse()
        ->and($result->reason)->toBe('automation_paused');
});

test('recordFollowUpSent increments count and stores timestamp', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    makeFollowUpService()->recordFollowUpSent($lead);

    $custom = $lead->fresh()->memory->custom_fields;
    expect($custom['follow_up_count'])->toBe(1)
        ->and($custom)->toHaveKey('follow_up_1_sent_at');
});
