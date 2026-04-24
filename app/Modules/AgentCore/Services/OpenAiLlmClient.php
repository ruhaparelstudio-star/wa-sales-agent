<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\DTOs\LlmResponse;
use App\Modules\AgentCore\Enums\LlmMode;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

class OpenAiLlmClient implements LlmClientInterface
{
    public const MODEL = 'gpt-5'; // fallback; override via OPENAI_MODEL=gpt-4.5-mini in .env

    public function __construct(
        private readonly ClientContract $client,
        private readonly string $model = self::MODEL,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $options = []): LlmResponse
    {
        $maxCompletionTokens = $options['max_completion_tokens']
            ?? $options['max_tokens']
            ?? 400;

        if ($this->usesResponsesApi()) {
            $response = $this->client->responses()->create($this->buildResponsesPayload(
                $messages,
                $options,
                $maxCompletionTokens,
            ));

            $content = $response->outputText;
            if ($content === null || trim($content) === '') {
                throw new RuntimeException($this->emptyResponsesApiMessage($response));
            }

            return new LlmResponse(
                content:          $content,
                promptTokens:     (int) ($response->usage?->inputTokens ?? 0),
                completionTokens: (int) ($response->usage?->outputTokens ?? 0),
                totalTokens:      (int) ($response->usage?->totalTokens ?? 0),
                model:            (string) ($response->model ?? $this->model),
            );
        }

        $response = $this->client->chat()->create($this->buildChatPayload(
            $messages,
            $options,
            $maxCompletionTokens,
        ));

        $content = $response->choices[0]->message->content ?? null;
        if ($content === null || trim($content) === '') {
            throw new RuntimeException('OpenAI returned empty message content.');
        }

        return new LlmResponse(
            content:          (string) $content,
            promptTokens:     (int) ($response->usage->promptTokens ?? 0),
            completionTokens: (int) ($response->usage->completionTokens ?? 0),
            totalTokens:      (int) ($response->usage->totalTokens ?? 0),
            model:            (string) ($response->model ?? $this->model),
        );
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildChatPayload(array $messages, array $options, int $maxCompletionTokens): array
    {
        $payload = [
            'model'                 => $this->model,
            'messages'              => $messages,
            'max_completion_tokens' => $maxCompletionTokens,
        ];

        if (array_key_exists('temperature', $options) && $this->supportsTemperatureOverride()) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        return array_merge($payload, $options['extra'] ?? []);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildResponsesPayload(array $messages, array $options, int $maxCompletionTokens): array
    {
        $payload = [
            'model'             => $this->model,
            'input'             => $this->mapMessagesToResponsesInput($messages),
            'max_output_tokens' => $maxCompletionTokens,
        ];

        if (array_key_exists('temperature', $options) && $this->supportsTemperatureOverride()) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if (($options['mode'] ?? null) === LlmMode::Classifier) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_object',
                ],
            ];
        }

        return array_merge($payload, $options['extra'] ?? []);
    }

    private function usesResponsesApi(): bool
    {
        return str_starts_with($this->model, 'gpt-5');
    }

    private function emptyResponsesApiMessage(CreateResponse $response): string
    {
        $details = ['status=' . $response->status];

        if ($response->error?->message) {
            $details[] = 'error=' . $response->error->message;
        }

        if ($response->incompleteDetails?->reason) {
            $details[] = 'incomplete_reason=' . $response->incompleteDetails->reason;
        }

        if ($response->output !== []) {
            $details[] = 'output_items=' . count($response->output);
        }

        return 'OpenAI returned empty response output_text (' . implode(', ', $details) . ').';
    }

    private function supportsTemperatureOverride(): bool
    {
        // gpt-5 family (and other reasoning models) only accept the default temperature.
        return ! str_starts_with($this->model, 'gpt-5')
            && ! str_starts_with($this->model, 'o1')
            && ! str_starts_with($this->model, 'o3');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessagesToResponsesInput(array $messages): array
    {
        return array_map(function (array $message): array {
            $role = $message['role'];

            if (! in_array($role, ['system', 'developer', 'user'], true)) {
                $role = 'user';
            }

            return [
                'type' => 'message',
                'role' => $role,
                'content' => [[
                    'type' => 'input_text',
                    'text' => $message['content'],
                ]],
            ];
        }, $messages);
    }
}
