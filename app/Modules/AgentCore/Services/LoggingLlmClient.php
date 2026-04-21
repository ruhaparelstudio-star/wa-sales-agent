<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\DTOs\LlmResponse;
use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Models\LlmUsageLog;
use App\Modules\AgentCore\Support\AgentLog;
use Illuminate\Support\Str;

class LoggingLlmClient implements LlmClientInterface
{
    public function __construct(
        private readonly LlmClientInterface $inner,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     *
     * Required options:
     *   - tenant_id (int)
     *   - mode (LlmMode)
     * Optional:
     *   - conversation_id (int|null)
     *   - message_id (int|null)
     *   - trace_id (string|null)
     */
    public function complete(array $messages, array $options = []): LlmResponse
    {
        $startedAtMs = (int) round(microtime(true) * 1000);
        $response = $this->inner->complete($messages, $options);
        $latencyMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);

        $tenantId = $options['tenant_id'] ?? null;
        $mode     = $options['mode']      ?? null;

        if ($tenantId !== null && $mode instanceof LlmMode) {
            LlmUsageLog::create([
                'tenant_id'         => (int) $tenantId,
                'conversation_id'   => $options['conversation_id'] ?? null,
                'message_id'        => $options['message_id'] ?? null,
                'trace_id'          => $options['trace_id'] ?? null,
                'mode'              => $mode,
                'prompt_tokens'     => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens'      => $response->totalTokens,
                'latency_ms'        => $latencyMs,
                'prompt_hash'       => $this->hashMessages($messages),
                'response_excerpt'  => Str::limit((string) $response->content, 500, ''),
                'model'             => $response->model,
            ]);

            AgentLog::info('llm.call', [
                'mode' => $mode->value,
                'conv' => $options['conversation_id'] ?? null,
                'msg' => $options['message_id'] ?? null,
                'tokens' => [
                    'p' => $response->promptTokens,
                    'c' => $response->completionTokens,
                ],
                'latency_ms' => $latencyMs,
                'model' => $response->model,
            ]);
        }

        return $response;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function hashMessages(array $messages): string
    {
        $serialized = '';
        foreach ($messages as $m) {
            $serialized .= ($m['role'] ?? '') . "\x1f" . ($m['content'] ?? '') . "\x1e";
        }

        return sha1($serialized);
    }
}
