<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\Conversations\Enums\ConversationStage;
use InvalidArgumentException;

final class ClassifierOutput
{
    /**
     * @param  array<string, mixed>  $extractedFields
     * @param  list<string>          $missingCriticalFields
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $sentiment,
        public readonly array $extractedFields,
        public readonly bool $needsHandoff,
        public readonly ?string $handoffReason,
        public readonly float $confidence,
        public readonly ?ConversationStage $currentStage = null,
        public readonly ?ConversationStage $suggestedNextStage = null,
        public readonly array $missingCriticalFields = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        self::assertRequiredField($data, 'intent');
        self::assertRequiredField($data, 'sentiment');
        self::assertRequiredField($data, 'extracted_fields');
        self::assertRequiredField($data, 'needs_handoff');
        self::assertRequiredField($data, 'confidence');

        $intent = trim((string) $data['intent']);
        if ($intent === '') {
            throw new InvalidArgumentException('Classifier output field "intent" must be a non-empty string.');
        }

        $sentiment = trim((string) $data['sentiment']);
        if ($sentiment === '') {
            throw new InvalidArgumentException('Classifier output field "sentiment" must be a non-empty string.');
        }

        if (! is_array($data['extracted_fields'])) {
            throw new InvalidArgumentException('Classifier output field "extracted_fields" must be an object.');
        }

        if (! is_bool($data['needs_handoff'])) {
            throw new InvalidArgumentException('Classifier output field "needs_handoff" must be a boolean.');
        }

        if (! is_numeric($data['confidence'])) {
            throw new InvalidArgumentException('Classifier output field "confidence" must be numeric.');
        }

        if (array_key_exists('missing_critical_fields', $data) && ! is_array($data['missing_critical_fields'])) {
            throw new InvalidArgumentException('Classifier output field "missing_critical_fields" must be an array.');
        }

        $currentStage = self::parseStageField($data, 'current_stage');
        $suggestedStage = self::parseStageField($data, 'suggested_next_stage');

        $missing = $data['missing_critical_fields'] ?? [];
        $missing = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $missing,
        ), static fn (string $v): bool => $v !== ''));

        return new self(
            intent:                $intent,
            sentiment:             $sentiment,
            extractedFields:       $data['extracted_fields'],
            needsHandoff:          $data['needs_handoff'],
            handoffReason:         $data['handoff_reason'] ?? null,
            confidence:            (float) $data['confidence'],
            currentStage:          $currentStage,
            suggestedNextStage:    $suggestedStage,
            missingCriticalFields: $missing,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertRequiredField(array $data, string $field): void
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException(sprintf('Classifier output missing required field "%s".', $field));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function parseStageField(array $data, string $field): ?ConversationStage
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $stage = self::tryStage($value);
        if ($stage === null) {
            throw new InvalidArgumentException(sprintf('Classifier output field "%s" is not a valid stage.', $field));
        }

        return $stage;
    }

    private static function tryStage(mixed $value): ?ConversationStage
    {
        return ConversationStage::coerce($value);
    }
}
