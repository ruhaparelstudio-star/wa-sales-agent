<?php

use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\PaymentProofRoutingService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makePaymentProofRoutingService(): PaymentProofRoutingService
{
    $convService    = new ConversationService();
    $leadService    = new LeadService();
    $stageService   = new LeadStageService($leadService);
    $handoffService = new HandoffRequestService($convService, $leadService, $stageService);

    return new PaymentProofRoutingService($handoffService);
}

test('route creates handoff when sent invoice exists for lead', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $msg    = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'lead_id'         => $lead->id,
        'conversation_id' => $conv->id,
    ]);

    ClientInvoice::factory()->sent()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $handled = makePaymentProofRoutingService()->route($lead, $msg);

    expect($handled)->toBeTrue()
        ->and(HandoffRequest::where('lead_id', $lead->id)->count())->toBe(1)
        ->and(HandoffRequest::where('lead_id', $lead->id)->first()->reason)->toBe(HandoffReason::PaymentProof);
});

test('route returns false and creates no handoff when no active invoice', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $msg    = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'lead_id'         => $lead->id,
        'conversation_id' => $conv->id,
    ]);

    $handled = makePaymentProofRoutingService()->route($lead, $msg);

    expect($handled)->toBeFalse()
        ->and(HandoffRequest::where('lead_id', $lead->id)->count())->toBe(0);
});

test('route skips duplicate handoff for same lead', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $msg    = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'lead_id'         => $lead->id,
        'conversation_id' => $conv->id,
    ]);

    ClientInvoice::factory()->sent()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $service = makePaymentProofRoutingService();
    $service->route($lead, $msg);
    $service->route($lead, $msg);

    expect(HandoffRequest::where('lead_id', $lead->id)->count())->toBe(1);
});
