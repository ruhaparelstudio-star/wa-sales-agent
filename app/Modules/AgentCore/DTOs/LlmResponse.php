<?php

namespace App\Modules\AgentCore\DTOs;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly string $model,
    ) {}
}
