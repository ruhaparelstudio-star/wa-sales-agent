<?php

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeLeadService(): LeadService
{
    return new LeadService();
}

test('findOrCreate creates a new lead for an unknown phone number', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $lead = makeLeadService()->findOrCreateByPhone($tenant, '+6281234567890', $agent, '6281234567890@s.whatsapp.net');

    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($lead->phone_e164)->toBe('+6281234567890')
        ->and($lead->whatsapp_jid)->toBe('6281234567890@s.whatsapp.net')
        ->and($lead->tenant_id)->toBe($tenant->id)
        ->and($lead->status)->toBe(LeadStatus::New);
});

test('findOrCreate returns existing lead for known phone number', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $first  = makeLeadService()->findOrCreateByPhone($tenant, '+6281234567890', $agent);
    $second = makeLeadService()->findOrCreateByPhone($tenant, '+6281234567890', $agent);

    expect($second->id)->toBe($first->id)
        ->and(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('findOrCreate updates stored whatsapp jid when a more specific recipient becomes available', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $lead = makeLeadService()->findOrCreateByPhone($tenant, '+244529684836573', $agent);

    $updated = makeLeadService()->findOrCreateByPhone($tenant, '+244529684836573', $agent, '244529684836573@lid');

    expect($updated->id)->toBe($lead->id)
        ->and($updated->whatsapp_jid)->toBe('244529684836573@lid')
        ->and($updated->preferredWhatsAppRecipient())->toBe('244529684836573@lid');
});

test('findOrCreate reuses existing lead when whatsapp jid matches returning user', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $lead = makeLeadService()->findOrCreateByPhone($tenant, '+6281234567890', $agent, '6281234567890@s.whatsapp.net');

    $sameLead = makeLeadService()->findOrCreateByPhone($tenant, '6281234567890@s.whatsapp.net', $agent, '6281234567890@s.whatsapp.net');

    expect($sameLead->id)->toBe($lead->id)
        ->and(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('tenant isolation — same phone in different tenants creates separate leads', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $agentA  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenantA->id]);
    $agentB  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenantB->id]);
    $phone   = '+6281111111111';

    $leadA = makeLeadService()->findOrCreateByPhone($tenantA, $phone, $agentA);
    $leadB = makeLeadService()->findOrCreateByPhone($tenantB, $phone, $agentB);

    expect($leadA->id)->not->toBe($leadB->id)
        ->and($leadA->tenant_id)->toBe($tenantA->id)
        ->and($leadB->tenant_id)->toBe($tenantB->id);
});

test('pauseAutomation sets automation_paused to true', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->withAgent($agent)->create();

    makeLeadService()->pauseAutomation($lead);

    expect($lead->fresh()->automation_paused)->toBeTrue();
});

test('resumeAutomation sets automation_paused to false', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->withAgent($agent)->paused()->create();

    makeLeadService()->resumeAutomation($lead);

    expect($lead->fresh()->automation_paused)->toBeFalse();
});

test('getHotLeads returns only HOT and READY_FOR_HUMAN leads for the tenant', function () {
    $tenant = Tenant::factory()->create();
    Lead::factory()->count(2)->hot()->create(['tenant_id' => $tenant->id]);
    Lead::factory()->readyForHuman()->create(['tenant_id' => $tenant->id]);
    Lead::factory()->create(['tenant_id' => $tenant->id]);

    // Another tenant's hot lead — must not appear
    Lead::factory()->hot()->create();

    $hotLeads = makeLeadService()->getHotLeads($tenant);

    expect($hotLeads)->toHaveCount(3)
        ->and($hotLeads->pluck('tenant_id')->unique()->first())->toBe($tenant->id);
});
