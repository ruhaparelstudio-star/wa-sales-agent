<?php

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\DTOs\LlmResponse;
use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Models\LlmUsageLog;
use App\Modules\AgentCore\Services\LoggingLlmClient;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


test('each call is recorded in llm_usage_logs with tokens', function () {
    $tenant = Tenant::factory()->create();

    $inner = new class implements LlmClientInterface {
        public function complete(array $messages, array $options = []): LlmResponse
        {
            return new LlmResponse('hi', 10, 20, 30, 'gpt-4.1-mini');
        }
    };

    $client = new LoggingLlmClient($inner);

    $resp = $client->complete([['role' => 'user', 'content' => 'halo']], [
        'tenant_id'       => $tenant->id,
        'conversation_id' => null,
        'mode'            => LlmMode::Classifier,
    ]);

    $log = LlmUsageLog::first();

    expect($resp->content)->toBe('hi')
        ->and(LlmUsageLog::count())->toBe(1)
        ->and($log->tenant_id)->toBe($tenant->id)
        ->and($log->mode)->toBe(LlmMode::Classifier)
        ->and($log->prompt_tokens)->toBe(10)
        ->and($log->completion_tokens)->toBe(20)
        ->and($log->total_tokens)->toBe(30)
        ->and($log->model)->toBe('gpt-4.1-mini');
});

test('missing tenant_id skips logging but still returns response', function () {
    $inner = new class implements LlmClientInterface {
        public function complete(array $messages, array $options = []): LlmResponse
        {
            return new LlmResponse('hi', 1, 2, 3, 'gpt-4.1-mini');
        }
    };

    $resp = (new LoggingLlmClient($inner))->complete([], []);

    expect($resp->totalTokens)->toBe(3)
        ->and(LlmUsageLog::count())->toBe(0);
});
