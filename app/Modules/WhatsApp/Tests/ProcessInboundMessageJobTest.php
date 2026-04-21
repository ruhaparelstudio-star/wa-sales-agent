<?php

use App\Modules\AgentCore\Jobs\RunAgentCoreJob;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationLockService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\MediaHandlerService;
use App\Modules\Conversations\Services\MessageIngestService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Jobs\ProcessInboundMessageJob;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppInboundReceipt;
use App\Modules\WhatsApp\Services\DuplicateMessageGuardService;
use Illuminate\Support\Facades\Queue;

test('process inbound job only dispatches agent core once for duplicate whatsapp message', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $job = new ProcessInboundMessageJob(
        agentId: $agent->id,
        from: '+6281234567890',
        fromJid: '6281234567890@s.whatsapp.net',
        type: 'text',
        content: 'Halo, saya mau tanya paketnya',
        waMessageId: 'wamid-inbound-001',
        timestamp: now()->toISOString(),
        webhookIdempotencyKey: 'webhook-key-001',
    );

    $mediaHandler = Mockery::mock(MediaHandlerService::class);

    $job->handle(
        app(MessageIngestService::class),
        $mediaHandler,
        app(ConversationStateService::class),
        app(DuplicateMessageGuardService::class),
        new ConversationLockService(),
    );

    $job->handle(
        app(MessageIngestService::class),
        $mediaHandler,
        app(ConversationStateService::class),
        app(DuplicateMessageGuardService::class),
        new ConversationLockService(),
    );

    Queue::assertPushed(RunAgentCoreJob::class, 1);

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(1)
        ->and(WhatsAppInboundReceipt::query()->count())->toBe(1)
        ->and(WhatsAppInboundReceipt::query()->first()?->status?->value)->toBe('processed');
});
