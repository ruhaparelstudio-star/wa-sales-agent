<?php

namespace App\Modules\AgentCore\DTOs;

final class FollowUpCheckResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason = null,
        public readonly int $nextFollowUpNumber = 0,
    ) {}

    public static function eligible(int $nextNumber): self
    {
        return new self(true, null, $nextNumber);
    }

    public static function ineligible(string $reason): self
    {
        return new self(false, $reason, 0);
    }
}
