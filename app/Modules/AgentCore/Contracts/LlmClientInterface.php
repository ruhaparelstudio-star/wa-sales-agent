<?php

namespace App\Modules\AgentCore\Contracts;

use App\Modules\AgentCore\DTOs\LlmResponse;

interface LlmClientInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $options = []): LlmResponse;
}
