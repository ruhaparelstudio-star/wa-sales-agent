<?php

use App\Modules\AgentCore\Jobs\RunAgentCoreJob;
use App\Modules\AgentCore\Services\AgentOrchestrator;
use App\Modules\Conversations\Enums\MessageDirection;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationLockService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;

test('run agent core job skips superseded inbound messages', function () {
    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
    ]);
    $conversation = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    $olderMessage = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'Saya aris ka',
    ]);

    Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'direction' => MessageDirection::Inbound,
        'content' => 'boleh minta pricelistnya',
    ]);

    $orchestrator = Mockery::mock(AgentOrchestrator::class);
    $orchestrator->shouldNotReceive('handleInbound');

    $job = new RunAgentCoreJob($olderMessage);
    $job->handle($orchestrator, new ConversationLockService());
});
