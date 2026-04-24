<?php

namespace App\Modules\AgentCore\Dispatch;

final class ActionDispatchResult
{
    public function __construct(
        public readonly bool $shouldStop,
        public readonly bool $shouldRefreshSummary = false,
        public readonly ?string $noReplyReason = null,
    ) {}

    public static function continueToResponse(): self
    {
        return new self(shouldStop: false);
    }

    public static function stop(
        bool $shouldRefreshSummary = false,
        ?string $noReplyReason = null,
    ): self {
        return new self(
            shouldStop: true,
            shouldRefreshSummary: $shouldRefreshSummary,
            noReplyReason: $noReplyReason,
        );
    }
}
