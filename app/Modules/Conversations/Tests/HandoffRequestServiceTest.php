<?php

use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Enums\HandoffStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeHandoffRequestService(): HandoffRequestService
{
    $convService  = new ConversationService();
    $leadService  = new LeadService();
    $stageService = new LeadStageService($leadService);

    return new HandoffRequestService($convService, $leadService, $stageService);
}

test('create handoff pauses automation and marks conversation as handoff', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    makeHandoffRequestService()->create($lead, $conv, HandoffReason::ReadyToBook);

    expect($lead->fresh()->automation_paused)->toBeTrue()
        ->and($conv->fresh()->status)->toBe(ConversationStatus::Handoff)
        ->and($conv->fresh()->is_human_takeover)->toBeTrue()
        ->and($lead->fresh()->status)->toBe(LeadStatus::ReadyForHuman);
});

test('create handoff stores a HandoffRequest record with pending status', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $request = makeHandoffRequestService()->create(
        $lead, $conv, HandoffReason::Complaint, 'Lead sangat tidak puas'
    );

    expect($request)->toBeInstanceOf(HandoffRequest::class)
        ->and($request->status)->toBe(HandoffStatus::Pending)
        ->and($request->reason)->toBe(HandoffReason::Complaint)
        ->and($request->reason_detail)->toBe('Lead sangat tidak puas')
        ->and($request->tenant_id)->toBe($tenant->id);
});

test('resolve sets status to resolved and records resolver', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv    = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $user    = User::factory()->create();
    $request = HandoffRequest::factory()->create([
        'tenant_id'       => $tenant->id,
        'lead_id'         => $lead->id,
        'conversation_id' => $conv->id,
        'status'          => HandoffStatus::Pending,
    ]);

    makeHandoffRequestService()->resolve($request, $user);

    expect($request->fresh()->status)->toBe(HandoffStatus::Resolved)
        ->and($request->fresh()->resolved_by)->toBe($user->id)
        ->and($request->fresh()->resolved_at)->not->toBeNull();
});

test('getPendingForTenant returns only pending requests for that tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $convA = Conversation::factory()->create(['tenant_id' => $tenantA->id, 'lead_id' => $leadA->id]);

    HandoffRequest::factory()->count(2)->create([
        'tenant_id'       => $tenantA->id,
        'lead_id'         => $leadA->id,
        'conversation_id' => $convA->id,
        'status'          => HandoffStatus::Pending,
    ]);

    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    $convB = Conversation::factory()->create(['tenant_id' => $tenantB->id, 'lead_id' => $leadB->id]);
    HandoffRequest::factory()->create([
        'tenant_id'       => $tenantB->id,
        'lead_id'         => $leadB->id,
        'conversation_id' => $convB->id,
        'status'          => HandoffStatus::Pending,
    ]);

    $pending = makeHandoffRequestService()->getPendingForTenant($tenantA);

    expect($pending)->toHaveCount(2)
        ->and($pending->pluck('tenant_id')->unique()->first())->toBe($tenantA->id);
});
