<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Conversations\Enums\ConversationStage;

test('classifier output rejects missing required fields', function () {
    ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
    ]);
})->throws(InvalidArgumentException::class, 'missing required field "extracted_fields"');

test('classifier output accepts valid structured payload', function () {
    $output = ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [
            'service_type' => 'wedding',
            'event_date' => '2026-12-12',
        ],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.91,
        'current_stage' => 'qualification',
        'suggested_next_stage' => 'package_recommendation',
        'missing_critical_fields' => ['location'],
    ]);

    expect($output->intent)->toBe('tanya_harga')
        ->and($output->sentiment)->toBe('neutral')
        ->and($output->extractedFields['service_type'])->toBe('wedding')
        ->and($output->currentStage)->toBe(ConversationStage::Qualification)
        ->and($output->suggestedNextStage)->toBe(ConversationStage::PackageRecommendation)
        ->and($output->missingCriticalFields)->toBe(['location']);
});
