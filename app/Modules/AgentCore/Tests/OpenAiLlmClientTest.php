<?php

use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Services\OpenAiLlmClient;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Contracts\Resources\ResponsesContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Responses\CreateResponse as ResponsesCreateResponse;

test('gpt 5 models use responses api output text', function () {
    $responses = \Mockery::mock(ResponsesContract::class);
    $responses->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(function (array $payload): bool {
            expect($payload['model'])->toBe('gpt-5-mini')
                ->and($payload['max_output_tokens'])->toBe(123)
                ->and($payload['temperature'])->toBe(0.1)
                ->and($payload['input'][0]['role'])->toBe('system')
                ->and($payload['input'][0]['content'][0]['type'])->toBe('input_text')
                ->and($payload['input'][1]['role'])->toBe('user')
                ->and($payload['input'][1]['content'][0]['text'])->toBe('Halo');

            return true;
        }))
        ->andReturn(ResponsesCreateResponse::fake([
            'model' => 'gpt-5-mini-2025-08-07',
            'output' => [[
                'type' => 'message',
                'id' => 'msg_test',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"intent":"small_talk"}',
                    'annotations' => [],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 11,
                'input_tokens_details' => [
                    'cached_tokens' => 0,
                ],
                'output_tokens' => 7,
                'output_tokens_details' => [
                    'reasoning_tokens' => 0,
                ],
                'total_tokens' => 18,
            ],
        ]));

    $client = \Mockery::mock(ClientContract::class);
    $client->shouldReceive('responses')->once()->andReturn($responses);
    $client->shouldNotReceive('chat');

    $llm = new OpenAiLlmClient($client, 'gpt-5-mini');

    $response = $llm->complete([
        ['role' => 'system', 'content' => 'Only return JSON'],
        ['role' => 'user', 'content' => 'Halo'],
    ], [
        'mode' => LlmMode::Classifier,
        'temperature' => 0.1,
        'max_tokens' => 123,
    ]);

    expect($response->content)->toStartWith('{"intent":"small_talk"}')
        ->and($response->promptTokens)->toBe(11)
        ->and($response->completionTokens)->toBe(7)
        ->and($response->totalTokens)->toBe(18)
        ->and($response->model)->toBe('gpt-5-mini-2025-08-07');
});

test('classifier mode forces json object format on responses api', function () {
    $responses = \Mockery::mock(ResponsesContract::class);
    $responses->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(function (array $payload): bool {
            expect($payload['text'])->toBe([
                'format' => [
                    'type' => 'json_object',
                ],
            ]);

            return true;
        }))
        ->andReturn(ResponsesCreateResponse::fake([
            'output' => [[
                'type' => 'message',
                'id' => 'msg_json',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"intent":"package_inquiry"}',
                    'annotations' => [],
                ]],
            ]],
        ]));

    $client = \Mockery::mock(ClientContract::class);
    $client->shouldReceive('responses')->once()->andReturn($responses);

    $llm = new OpenAiLlmClient($client, 'gpt-5-mini');

    $response = $llm->complete([
        ['role' => 'user', 'content' => 'Paketnya apa aja ka?'],
    ], [
        'mode' => LlmMode::Classifier,
    ]);

    expect($response->content)->toContain('"package_inquiry"');
});

test('non gpt 5 models keep using chat completions', function () {
    $chat = \Mockery::mock(ChatContract::class);
    $chat->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(function (array $payload): bool {
            expect($payload['model'])->toBe('gpt-4o-mini')
                ->and($payload['max_completion_tokens'])->toBe(77)
                ->and($payload['temperature'])->toBe(0.7)
                ->and($payload['messages'][0]['content'])->toBe('Hello');

            return true;
        }))
        ->andReturn(ChatCreateResponse::fake([
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hi there',
                    'function_call' => null,
                    'tool_calls' => [],
                ],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 2,
                'total_tokens' => 7,
            ],
        ]));

    $client = \Mockery::mock(ClientContract::class);
    $client->shouldReceive('chat')->once()->andReturn($chat);
    $client->shouldNotReceive('responses');

    $llm = new OpenAiLlmClient($client, 'gpt-4o-mini');

    $response = $llm->complete([
        ['role' => 'user', 'content' => 'Hello'],
    ], [
        'temperature' => 0.7,
        'max_tokens' => 77,
    ]);

    expect($response->content)->toBe('Hi there')
        ->and($response->totalTokens)->toBe(7);
});
