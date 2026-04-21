<?php

namespace App\Modules\AgentCore\DTOs;

final class GuardrailResult
{
    public function __construct(
        public readonly bool $blocked,
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(false, null);
    }

    public static function block(string $reason): self
    {
        return new self(true, $reason);
    }
}
