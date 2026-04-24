<?php

use App\Modules\AgentCore\Services\OpenAiLlmClient;
use App\Modules\AgentCore\Support\OpenAiRuntimeConfig;
use OpenAI\Contracts\ClientContract;

test('openai health check skip-request reports resolved fallback model', function () {
    config()->set('services.openai.enabled', 'true');
    config()->set('services.openai.api_key', 'sk-proj-test-123');
    config()->set('services.openai.model', 'gpt-5.3');
    config()->set('services.openai.timeout', '45');
    config()->set('services.openai.max_output_tokens', '321');

    $client = \Mockery::mock(ClientContract::class);
    $client->shouldNotReceive('chat');
    $client->shouldNotReceive('responses');
    app()->instance(ClientContract::class, $client);
    app()->instance(OpenAiRuntimeConfig::class, new OpenAiRuntimeConfig((array) config('services.openai')));
    app()->instance(OpenAiLlmClient::class, new OpenAiLlmClient($client, 'gpt-5.3', true, 321));

    $this->artisan('openai:health-check --skip-request')
        ->expectsOutput('OpenAI health check')
        ->expectsOutput('Enabled       : yes')
        ->expectsOutput('API key set   : yes')
        ->expectsOutput('API key prefix: sk-proj')
        ->expectsOutput('Resolved model: gpt-5.3')
        ->expectsOutput('Timeout       : 45s')
        ->expectsOutput('Max output    : 321')
        ->expectsOutput('Skip request enabled; network call not executed.')
        ->assertExitCode(0);
});

test('openai health check reports disabled runtime cleanly', function () {
    config()->set('services.openai.enabled', 'false');
    config()->set('services.openai.api_key', '');
    config()->set('services.openai.model', 'gpt-5.3');
    config()->set('services.openai.timeout', '30');
    config()->set('services.openai.max_output_tokens', '400');

    $client = \Mockery::mock(ClientContract::class);
    $client->shouldNotReceive('chat');
    $client->shouldNotReceive('responses');
    app()->instance(ClientContract::class, $client);
    app()->instance(OpenAiRuntimeConfig::class, new OpenAiRuntimeConfig((array) config('services.openai')));
    app()->instance(OpenAiLlmClient::class, new OpenAiLlmClient($client, 'gpt-5.3', false, 400));

    $this->artisan('openai:health-check --skip-request')
        ->expectsOutput('OpenAI health check')
        ->expectsOutput('Enabled       : no')
        ->expectsOutput('OPENAI_ENABLED=false, request path is intentionally disabled.')
        ->assertExitCode(0);
});
