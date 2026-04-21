<?php

use App\Modules\Billing\Jobs\SendBillingAlertsJob;
use App\Modules\Billing\Jobs\GenerateRenewalInvoicesJob;
use App\Modules\AgentCore\Jobs\FollowUpSchedulerJob;
use App\Modules\AgentCore\Jobs\SendFollowUpMessageJob;
use App\Modules\Dashboard\Jobs\SnapshotDashboardMetricsJob;
use App\Modules\Dashboard\Notifications\HandoffCreatedNotification;
use App\Modules\Dashboard\Notifications\AgentDisconnectedNotification;
use App\Modules\Dashboard\Notifications\HotLeadAlertNotification;
use App\Modules\Dashboard\Notifications\AgentSlotLimitNotification;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;


// ── Queue placement ────────────────────────────────────────────────────────

test('SendBillingAlertsJob is on medium queue', function () {
    Queue::fake();
    SendBillingAlertsJob::dispatch();
    Queue::assertPushedOn('medium', SendBillingAlertsJob::class);
});

test('GenerateRenewalInvoicesJob is on low queue', function () {
    Queue::fake();
    GenerateRenewalInvoicesJob::dispatch();
    Queue::assertPushedOn('low', GenerateRenewalInvoicesJob::class);
});

test('FollowUpSchedulerJob is on low queue', function () {
    Queue::fake();
    FollowUpSchedulerJob::dispatch();
    Queue::assertPushedOn('low', FollowUpSchedulerJob::class);
});

test('SnapshotDashboardMetricsJob is on low queue', function () {
    Queue::fake();
    SnapshotDashboardMetricsJob::dispatch();
    Queue::assertPushedOn('low', SnapshotDashboardMetricsJob::class);
});

// ── Notification channel rules ─────────────────────────────────────────────

test('HandoffCreatedNotification uses database and mail channels', function () {
    $handoff = new \App\Modules\Conversations\Models\HandoffRequest();
    $handoff->id = 1;
    $handoff->lead_id = 1;
    $handoff->conversation_id = 1;
    $handoff->reason = \App\Modules\Conversations\Enums\HandoffReason::Other;
    $handoff->created_at = now();

    $notification = new HandoffCreatedNotification($handoff);

    expect($notification->via(new User()))->toBe(['database', 'mail']);
});

test('HotLeadAlertNotification uses database channel ONLY — no email', function () {
    $tenant = Tenant::factory()->create();
    $lead   = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => LeadStatus::Hot,
    ]);

    $notification = new HotLeadAlertNotification($lead);

    expect($notification->via(new User()))
        ->toBe(['database'])
        ->not->toContain('mail');
});

test('AgentSlotLimitNotification uses database channel ONLY — no email', function () {
    $notification = new AgentSlotLimitNotification('Starter', 1, 1);

    expect($notification->via(new User()))
        ->toBe(['database'])
        ->not->toContain('mail');
});

test('AgentDisconnectedNotification uses database and mail channels', function () {
    $agent = new \App\Modules\WhatsApp\Models\WhatsAppAgent();
    $agent->id = 'uuid-test';
    $agent->phone_number = '+6281234567890';

    $notification = new AgentDisconnectedNotification($agent, 'logout');

    expect($notification->via(new User()))->toBe(['database', 'mail']);
});

// ── HandoffCreatedNotification — tenant isolation ──────────────────────────

test('HandoffCreatedNotification is only dispatched to admins of the correct tenant', function () {
    Notification::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $adminA = User::factory()->create();
    $adminB = User::factory()->create();

    $tenantA->tenantUsers()->create(['user_id' => $adminA->id, 'role' => 'vendor_admin']);
    $tenantB->tenantUsers()->create(['user_id' => $adminB->id, 'role' => 'vendor_admin']);

    $lead  = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $conv  = \App\Modules\Conversations\Models\Conversation::factory()->create([
        'tenant_id' => $tenantA->id,
        'lead_id'   => $lead->id,
    ]);

    $service = app(\App\Modules\Conversations\Services\HandoffRequestService::class);
    $service->create($lead, $conv, \App\Modules\Conversations\Enums\HandoffReason::Other);

    Notification::assertSentTo($adminA, HandoffCreatedNotification::class);
    Notification::assertNotSentTo($adminB, HandoffCreatedNotification::class);
});
