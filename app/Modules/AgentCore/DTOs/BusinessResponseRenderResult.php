<?php

namespace App\Modules\AgentCore\DTOs;

final class BusinessResponseRenderResult
{
    public function __construct(
        public readonly string $deliveryMode,
        public readonly ?string $text = null,
        public readonly ?string $followUpText = null,
        public readonly ?string $caption = null,
        public readonly ?string $nextBestAction = null,
        public readonly ?string $toolResultSummary = null,
    ) {}

    public static function text(
        string $text,
        ?string $nextBestAction = null,
        ?string $toolResultSummary = null,
    ): self {
        return new self(
            deliveryMode: 'text',
            text: $text,
            nextBestAction: $nextBestAction,
            toolResultSummary: $toolResultSummary,
        );
    }

    public static function documentFollowUp(
        string $followUpText,
        string $caption,
        ?string $nextBestAction = null,
        ?string $toolResultSummary = null,
    ): self {
        return new self(
            deliveryMode: 'document_follow_up',
            followUpText: $followUpText,
            caption: $caption,
            nextBestAction: $nextBestAction,
            toolResultSummary: $toolResultSummary,
        );
    }
}
