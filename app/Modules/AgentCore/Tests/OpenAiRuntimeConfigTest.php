<?php

use App\Modules\AgentCore\Support\OpenAiRuntimeConfig;

test('openai runtime config normalizes env sourced values', function () {
    $config = new OpenAiRuntimeConfig([
        'enabled' => 'true',
        'api_key' => 'sk-proj-test-123',
        'organization' => '',
        'model' => 'gpt-5.3',
        'timeout' => '30',
        'max_output_tokens' => '400',
    ]);

    expect($config->enabled())->toBeTrue()
        ->and($config->apiKey())->toBe('sk-proj-test-123')
        ->and($config->organization())->toBeNull()
        ->and($config->model())->toBe('gpt-5.3')
        ->and($config->timeout())->toBe(30)
        ->and($config->maxOutputTokens())->toBe(400);
});

test('openai runtime config rejects missing model', function () {
    $config = new OpenAiRuntimeConfig([
        'enabled' => 'true',
        'api_key' => 'sk-proj-test-123',
        'organization' => null,
        'model' => '',
        'timeout' => '30',
        'max_output_tokens' => '400',
    ]);

    expect(fn () => $config->model())
        ->toThrow(\RuntimeException::class, 'OPENAI_MODEL is missing from runtime configuration.');
});
