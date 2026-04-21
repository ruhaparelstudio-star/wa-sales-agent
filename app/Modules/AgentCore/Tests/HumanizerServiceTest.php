<?php

use App\Modules\AgentCore\Services\HumanizerService;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;

test('humanizer rotates repeated opening when last assistant message used the same opener', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. Paket kemarin yang paling sering dipilih itu Gold.',
    ]);

    $humanized = (new HumanizerService())->humanize(
        'Siap, aku bantu jelaskan ya. Paket yang paling cocok biasanya Gold.',
        $lead,
        $conv,
    );

    expect($humanized)->toStartWith('Oke,')
        ->and($humanized)->not->toStartWith('Siap,');
});

test('humanizer normalizes noisy punctuation and spacing', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'content' => 'boleh minta pricelistnya?',
    ]);

    $humanized = (new HumanizerService())->humanize(
        'Siap,  aku bantu ya!!!   Paketnya ada beberapa opsi??',
        $lead,
        $conv,
        $message,
    );

    expect($humanized)->toBe('Siap, aku bantu ya! Paketnya ada beberapa opsi?');
});

test('humanizer preserves payment facts while rotating repeated opening', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $conv = Conversation::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
    ]);

    ConversationState::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'lead_id' => $lead->id,
        'last_agent_message' => 'Siap, aku bantu jelaskan ya. DP sebelumnya kita bahas 20%.',
    ]);

    $result = (new HumanizerService())->humanizeWithMetadata(
        'Siap, DP-nya 30% dari total dan transfer ke rekening BCA ya.',
        $lead,
        $conv,
    );

    expect($result['message'])->toStartWith('Oke,')
        ->and($result['message'])->toContain('30%')
        ->and($result['message'])->toContain('rekening BCA')
        ->and($result['reasons'])->toContain('rotate_opening');
});
