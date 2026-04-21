<?php

use App\Modules\Conversations\Actions\PaymentProofDetectedAction;
use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\HandoffRequest;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Conversations\Services\MediaHandlerService;
use App\Modules\Invoice\Services\PaymentProofRoutingService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Conversations\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;


function makeMediaHandlerService(): MediaHandlerService
{
    $convService    = new ConversationService();
    $leadService    = new LeadService();
    $stageService   = new LeadStageService($leadService);
    $handoffService = new HandoffRequestService($convService, $leadService, $stageService);
    $routingService = new PaymentProofRoutingService($handoffService);
    $action         = new PaymentProofDetectedAction($handoffService, $routingService);

    return new MediaHandlerService($action);
}

test('payment proof image from hot lead triggers handoff request', function () {
    Storage::fake('local');
    Http::fake(['*' => Http::response('fake-image-bytes', 200)]);

    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->hot()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $message = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
    ]);

    makeMediaHandlerService()->handleInboundMedia($message, $tenant);

    expect(HandoffRequest::where('lead_id', $lead->id)->count())->toBe(1)
        ->and(HandoffRequest::where('lead_id', $lead->id)->first()->reason->value)->toBe('payment_proof');
});

test('payment proof from non-hot lead does not trigger handoff', function () {
    Storage::fake('local');
    Http::fake(['*' => Http::response('fake-image-bytes', 200)]);

    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->qualified()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $message = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
    ]);

    makeMediaHandlerService()->handleInboundMedia($message, $tenant);

    expect(HandoffRequest::where('lead_id', $lead->id)->count())->toBe(0);
});

test('storagePath returns correct convention', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv    = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $message = Message::factory()->image()->create([
        'tenant_id'       => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id'         => $lead->id,
        'wa_message_id'   => 'WAMID_TEST123',
        'media_mime'      => 'image/jpeg',
    ]);

    $service = makeMediaHandlerService();
    $path    = $service->storagePath($tenant, $message);

    expect($path)->toStartWith("tenants/{$tenant->id}/media/")
        ->and($path)->toEndWith('WAMID_TEST123.jpg');
});
