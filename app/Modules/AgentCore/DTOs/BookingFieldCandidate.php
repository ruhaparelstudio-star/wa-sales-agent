<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;
use App\Modules\AgentCore\Enums\FieldCandidateStatus;

final class BookingFieldCandidate
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $validation
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly ?string $rawValue,
        public readonly string|int|float|bool|null $normalizedValue,
        public readonly float $confidence,
        public readonly string $source,
        public readonly FieldCandidateStatus $status,
        public readonly bool $requiresConfirmation,
        public readonly array $validation = [],
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     field_name: string,
     *     raw_value: ?string,
     *     normalized_value: string|int|float|bool|null,
     *     confidence: float,
     *     source: string,
     *     status: string,
     *     requires_confirmation: bool,
     *     validation: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'field_name' => $this->fieldName,
            'raw_value' => $this->rawValue,
            'normalized_value' => $this->normalizeValue($this->normalizedValue),
            'confidence' => $this->confidence,
            'source' => $this->source,
            'status' => $this->status->value,
            'requires_confirmation' => $this->requiresConfirmation,
            'validation' => $this->normalizeValue($this->validation),
        ];
    }
}
