<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\Services\ResponseEvaluatorService;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationState;

function makeEvaluatorConvAtStage(ConversationStage $stage): Conversation
{
    $conv = Conversation::factory()->make(['stage' => $stage->value]);
    return $conv;
}

test('flags forbidden promises as false', function () {
    $evaluator = new ResponseEvaluatorService();
    $score = $evaluator->evaluate(
        'Paket kami pasti available untuk tanggal tersebut, harga termurah di kota!',
        makeEvaluatorConvAtStage(ConversationStage::Qualification),
        null,
        null,
        'text',
    );

    expect($score['no_false_promises'])->toBeFalse();
});

test('passes no_false_promises on clean reply', function () {
    $evaluator = new ResponseEvaluatorService();
    $score = $evaluator->evaluate(
        'Siap kak, paket kami biasanya mulai dari 10 juta. Mau aku kirim pricelist lengkapnya?',
        makeEvaluatorConvAtStage(ConversationStage::Qualification),
        null,
        null,
        'text',
    );

    expect($score['no_false_promises'])->toBeTrue();
});

test('answered_latest_question is true when reply mentions pricing topic for price_inquiry', function () {
    $evaluator = new ResponseEvaluatorService();
    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_harga',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]);

    $score = $evaluator->evaluate(
        'Paket Silver Rp 15 juta, Gold Rp 25 juta.',
        makeEvaluatorConvAtStage(ConversationStage::Qualification),
        $classifier,
        null,
        'text',
    );

    expect($score['answered_latest_question'])->toBeTrue();
});

test('weak meta clarification does not pass as a valid package inquiry answer', function () {
    $evaluator = new ResponseEvaluatorService();
    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]);

    $score = $evaluator->evaluate(
        'Kalau ada paket yang kamu incar, sebutkan saja, nanti aku bantu jelaskan coverage utamanya.',
        makeEvaluatorConvAtStage(ConversationStage::PackageRecommendation),
        $classifier,
        null,
        'text',
    );

    expect($score['answered_latest_question'])->toBeFalse();
});

test('concrete package catalog answer passes package inquiry evaluation', function () {
    $evaluator = new ResponseEvaluatorService();
    $classifier = ClassifierOutput::fromArray([
        'intent' => 'tanya_paket',
        'sentiment' => 'neutral',
        'extracted_fields' => [],
        'needs_handoff' => false,
        'handoff_reason' => null,
        'confidence' => 0.9,
    ]);

    $score = $evaluator->evaluate(
        'Paket Silver: include 6 jam dokumentasi foto. Paket Gold: include 8 jam foto video dan album premium.',
        makeEvaluatorConvAtStage(ConversationStage::PackageRecommendation),
        $classifier,
        null,
        'text',
    );

    expect($score['answered_latest_question'])->toBeTrue();
});

test('has_cta_when_due is false when closing stage reply has no CTA', function () {
    $evaluator = new ResponseEvaluatorService();
    $score = $evaluator->evaluate(
        'Paketnya lengkap kok, banyak pilihannya.',
        makeEvaluatorConvAtStage(ConversationStage::Closing),
        null,
        null,
        'text',
    );

    expect($score['has_cta_when_due'])->toBeFalse();
});

test('has_cta_when_due is true when closing stage reply includes CTA marker', function () {
    $evaluator = new ResponseEvaluatorService();
    $score = $evaluator->evaluate(
        'Siap, kalau cocok kita langsung booking ya. Silakan transfer DP untuk konfirmasi.',
        makeEvaluatorConvAtStage(ConversationStage::Closing),
        null,
        null,
        'text',
    );

    expect($score['has_cta_when_due'])->toBeTrue();
});

test('stage_aligned is false for handoff response in early stages', function () {
    $evaluator = new ResponseEvaluatorService();
    $score = $evaluator->evaluate(
        'Akan saya teruskan ke tim admin.',
        makeEvaluatorConvAtStage(ConversationStage::Qualification),
        null,
        null,
        'handoff',
    );

    expect($score['stage_aligned'])->toBeFalse();
});

test('repeated_question detects reasking the same question', function () {
    $evaluator = new ResponseEvaluatorService();
    $state = new ConversationState(['last_agent_question' => 'Untuk tanggal berapa ya acaranya?']);

    $score = $evaluator->evaluate(
        'Baik kak, untuk tanggal berapa ya acaranya?',
        makeEvaluatorConvAtStage(ConversationStage::NeedsDiscovery),
        null,
        $state,
        'text',
    );

    expect($score['repeated_question'])->toBeTrue();
});
