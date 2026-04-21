<?php

namespace App\Modules\AgentCore\Tests\Support;

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\DTOs\LlmResponse;

class FakeLlmClient implements LlmClientInterface
{
    /** @var array<int, LlmResponse> */
    private array $responses = [];

    /** @var array<int, array{messages: array, options: array}> */
    public array $calls = [];

    public function queueResponse(string $content, int $prompt = 10, int $completion = 20, string $model = 'gpt-4.1-mini'): void
    {
        $this->responses[] = new LlmResponse($content, $prompt, $completion, $prompt + $completion, $model);
    }

    public function complete(array $messages, array $options = []): LlmResponse
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        if (empty($this->responses)) {
            return new LlmResponse('', 0, 0, 0, 'gpt-4.1-mini');
        }

        return array_shift($this->responses);
    }
}
