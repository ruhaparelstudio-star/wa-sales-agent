<?php

use App\Modules\AgentCore\Jobs\FollowUpSchedulerJob;
use App\Modules\AgentCore\Jobs\SendFollowUpMessageJob;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;


beforeEach(function () {
    Queue::fake();
});

function makeLead(array $attrs = []): Lead
{
    $tenant = Tenant::factory()->create();
    $agent  = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    Subscription::factory()->active()->create(['tenant_id' => $tenant->id]);

    $lead = Lead::factory()->create(array_merge([
        'tenant_id'         => $tenant->id,
        'whatsapp_agent_id' => $agent->id,
        'status'            => LeadStatus::Qualified,
        'automation_paused' => false,
        'last_message_at'   => now()->subHours(24),
    ], $attrs));

    Conversation::factory()->active()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    return $lead;
}

test('dispatches SendFollowUpMessageJob for eligible lead', function () {
    makeLead();

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertPushed(SendFollowUpMessageJob::class);
});

test('does not dispatch follow-up for automation_paused lead', function () {
    makeLead(['automation_paused' => true]);

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertNotPushed(SendFollowUpMessageJob::class);
});

test('does not dispatch follow-up for closed_won lead', function () {
    makeLead(['status' => LeadStatus::ClosedWon]);

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertNotPushed(SendFollowUpMessageJob::class);
});

test('does not dispatch follow-up for ready_for_human lead', function () {
    makeLead(['status' => LeadStatus::ReadyForHuman]);

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertNotPushed(SendFollowUpMessageJob::class);
});

test('does not dispatch follow-up when last_message_at is too recent', function () {
    makeLead(['last_message_at' => now()->subHours(5)]);

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertNotPushed(SendFollowUpMessageJob::class);
});

test('follow-up job is dispatched to low queue', function () {
    makeLead();

    app(FollowUpSchedulerJob::class)->handle(
        app(\App\Modules\AgentCore\Services\FollowUpPolicyService::class),
        app(\App\Modules\AgentCore\Services\GuardrailService::class),
    );

    Queue::assertPushedOn('low', SendFollowUpMessageJob::class);
});
