<?php

use App\Modules\Conversations\Enums\ConversationStatus;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeConversationService(): ConversationService
{
    return new ConversationService();
}

test('openOrResume creates a new conversation when none exists', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $conv = makeConversationService()->openOrResume($lead, $agent);

    expect($conv)->toBeInstanceOf(Conversation::class)
        ->and($conv->status)->toBe(ConversationStatus::Active)
        ->and($conv->lead_id)->toBe($lead->id)
        ->and($conv->tenant_id)->toBe($tenant->id);
});

test('openOrResume resumes existing active conversation', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $first  = makeConversationService()->openOrResume($lead, $agent);
    $second = makeConversationService()->openOrResume($lead, $agent);

    expect($second->id)->toBe($first->id)
        ->and(Conversation::where('lead_id', $lead->id)->count())->toBe(1);
});

test('openOrResume updates active conversation to latest agent', function () {
    $tenant = Tenant::factory()->create();
    $oldAgent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $newAgent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $existing = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'whatsapp_agent_id' => $oldAgent->id,
    ]);

    $result = makeConversationService()->openOrResume($lead, $newAgent);

    expect($result->id)->toBe($existing->id)
        ->and($result->fresh()->whatsapp_agent_id)->toBe($newAgent->id);
});

test('openOrResume returns existing handoff conversation without creating a new one', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $existing = Conversation::factory()->handoff()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $result = makeConversationService()->openOrResume($lead, $agent);

    expect($result->id)->toBe($existing->id)
        ->and(Conversation::where('lead_id', $lead->id)->count())->toBe(1);
});

test('openOrResume creates new conversation when only a closed one exists', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Conversation::factory()->closed()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    $conv = makeConversationService()->openOrResume($lead, $agent);

    expect($conv->status)->toBe(ConversationStatus::Active)
        ->and(Conversation::where('lead_id', $lead->id)->count())->toBe(2);
});

test('getRecentMessages returns max 6 messages in chronological order', function () {
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead   = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv   = Conversation::factory()->active()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    // Create 10 messages
    foreach (range(1, 10) as $i) {
        Message::factory()->create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conv->id,
            'lead_id'         => $lead->id,
            'created_at'      => now()->subMinutes(10 - $i),
        ]);
    }

    $messages = makeConversationService()->getRecentMessages($conv, 6);

    expect($messages)->toHaveCount(6);

    // Should be in chronological order (oldest first among the 6 most recent)
    expect($messages->first()->created_at->lt($messages->last()->created_at))->toBeTrue();
});
