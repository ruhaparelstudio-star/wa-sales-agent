<?php

namespace App\Modules\AgentCore\DTOs;

final class InterpretationResult
{
    /**
     * @param  array<string, mixed>  $slots
     */
    public function __construct(
        public readonly string $canonicalIntent,
        public readonly string $legacyIntent,
        public readonly array $slots,
        public readonly float $confidence,
        public readonly string $source = 'rules',
    ) {}

    public function hasClearIntent(float $threshold = 0.78): bool
    {
        return $this->canonicalIntent !== 'unclear' && $this->confidence >= $threshold;
    }
}
