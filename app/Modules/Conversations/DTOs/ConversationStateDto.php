<?php

namespace App\Modules\Conversations\DTOs;

use App\Modules\Conversations\Enums\ConversationStage;

final class ConversationStateDto
{
    /**
     * @param  list<string>  $askedFields
     */
    public function __construct(
        public readonly ConversationStage $stage,
        public readonly array $askedFields,
        public readonly ?string $nextExpectedField,
        public readonly int $transitionCount,
    ) {}
}
