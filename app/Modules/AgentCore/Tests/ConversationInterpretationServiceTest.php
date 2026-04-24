<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\Services\ConversationInterpretationService;
use App\Modules\AgentCore\Services\IntentExtractionService;
use App\Modules\AgentCore\Services\SlotExtractionService;
use App\Modules\Conversations\Enums\ConversationStage;

function makeInterpretationService(): ConversationInterpretationService
{
    return new ConversationInterpretationService(
        new IntentExtractionService(),
        new SlotExtractionService(),
    );
}

test('interpret extracts canonical payment intent and slots from deterministic rules', function () {
    $result = makeInterpretationService()->interpret('Halo, untuk akad tanggal 12 Desember 2026 di Bandung jam 10.00-12.00 DP-nya berapa ya?');

    expect($result->canonicalIntent)->toBe('payment_inquiry')
        ->and($result->legacyIntent)->toBe('payment_inquiry')
        ->and($result->source)->toBe('rules')
        ->and($result->confidence)->toBeGreaterThan(0.9)
        ->and($result->slots['event_type'])->toBe('wedding')
        ->and($result->slots['event_date'])->toBe('2026-12-12')
        ->and($result->slots['event_time_start'])->toBe('10:00')
        ->and($result->slots['event_time_end'])->toBe('12:00')
        ->and($result->slots['location'])->toBe('Bandung')
        ->and($result->slots['payment_topic'])->toBe('down_payment');
});

test('mergeClassifierOutput prefers clear rule intent over other and keeps rule extracted slots', function () {
    $classifier = new ClassifierOutput(
        intent: 'other',
        sentiment: 'neutral',
        extractedFields: ['location' => null],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.61,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['event_date'],
    );

    $interpretation = makeInterpretationService()->interpret('Mau booking wedding di Bandung tanggal 12 desember 2026');
    $merged = makeInterpretationService()->mergeClassifierOutput($classifier, $interpretation);

    expect($merged->intent)->toBe('ready_to_book')
        ->and($merged->confidence)->toBeGreaterThanOrEqual($interpretation->confidence)
        ->and($merged->extractedFields['location'])->toBe('Bandung')
        ->and($merged->extractedFields['event_date'])->toBe('2026-12-12')
        ->and($merged->extractedFields['service_type'])->toBe('wedding');
});

test('resolveClassifierOutput does not override objection analyzer into ready_to_book from keyword-only rule', function () {
    $classifier = new ClassifierOutput(
        intent: 'objection_handling',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.92,
        currentStage: ConversationStage::Closing,
        suggestedNextStage: ConversationStage::Closing,
        missingCriticalFields: [],
    );

    $interpretation = new \App\Modules\AgentCore\DTOs\InterpretationResult(
        canonicalIntent: 'booking_intent',
        legacyIntent: 'ready_to_book',
        slots: [],
        confidence: 0.95,
        source: 'rules',
    );

    $resolved = makeInterpretationService()->resolveClassifierOutput($classifier, $interpretation, [
        'protect_analyzer_intents' => ['objection_handling', 'clarification', 'complaint'],
        'block_rule_ready_to_book' => true,
    ]);

    expect($resolved['raw_analyzer_intent'])->toBe('complaint')
        ->and($resolved['rule_intent'])->toBe('ready_to_book')
        ->and($resolved['final_intent'])->toBe('complaint')
        ->and($resolved['override_reason'])->toBe('guard:protected_analyzer_intent_blocks_ready_to_book_rule');
});

test('resolveClassifierOutput rejects payment override when analyzer still sees package inquiry', function () {
    $classifier = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::PackageRecommendation,
        suggestedNextStage: ConversationStage::PackageRecommendation,
        missingCriticalFields: [],
    );

    $interpretation = new \App\Modules\AgentCore\DTOs\InterpretationResult(
        canonicalIntent: 'payment_inquiry',
        legacyIntent: 'payment_inquiry',
        slots: ['package_interest' => 'prewedding'],
        confidence: 0.95,
        source: 'rules',
    );

    $resolved = makeInterpretationService()->resolveClassifierOutput($classifier, $interpretation);

    expect($resolved['final_intent'])->toBe('tanya_paket')
        ->and($resolved['override_reason'])->toBe('guard:package_or_price_intent_blocks_payment_rule')
        ->and($resolved['override_rejected_reason'])->toBe('guard:package_or_price_intent_blocks_payment_rule');
});

test('mergeClassifierOutput keeps normalized rule event date when classifier returns natural-language date', function () {
    $classifier = new ClassifierOutput(
        intent: 'qualification',
        sentiment: 'neutral',
        extractedFields: ['event_date' => '30 desember'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.88,
        currentStage: ConversationStage::Qualification,
        suggestedNextStage: ConversationStage::Qualification,
        missingCriticalFields: ['location'],
    );

    $interpretation = makeInterpretationService()->interpret('Tanggal 30 desember ka');
    $merged = makeInterpretationService()->mergeClassifierOutput($classifier, $interpretation);

    expect($merged->extractedFields['event_date'])->toBe(now()->format('Y') . '-12-30');
});

test('package commitment is treated as booking intent instead of generic package inquiry', function () {
    $result = makeInterpretationService()->interpret('Oke aku ambil paket gold aja untuk wedding di Bandung');

    expect($result->canonicalIntent)->toBe('booking_intent')
        ->and($result->legacyIntent)->toBe('ready_to_book')
        ->and($result->slots['package_interest'])->toBe('gold');
});

test('pricing clarification captures whether user wants price, package, or both', function () {
    $result = makeInterpretationService()->interpret('Aku mau tau harga dan isi paketnya ka');

    expect($result->slots['pricing_focus'])->toBe('price_and_package');
});

test('location extraction ignores generic explanation phrasing', function () {
    $result = makeInterpretationService()->interpret('Boleh di jelaskan saja ka');

    expect($result->slots)->not->toHaveKey('location');
});

test('toClassifierOutput creates safe fallback classifier from interpretation', function () {
    $interpretation = makeInterpretationService()->interpret('Tanggal 12 desember 2026 masih available ga?');
    $classifier = makeInterpretationService()->toClassifierOutput($interpretation, ConversationStage::Qualification);

    expect($classifier->intent)->toBe('availability')
        ->and($classifier->needsHandoff)->toBeTrue()
        ->and($classifier->handoffReason)->toBe('availability_check')
        ->and($classifier->suggestedNextStage)->toBe(ConversationStage::HandoffToHuman);
});

test('payment inquiry from discovery stage suggests PaymentDiscussion, not regression', function () {
    $interpretation = makeInterpretationService()->interpret('DP-nya berapa ya?');
    $classifier = makeInterpretationService()->toClassifierOutput($interpretation, ConversationStage::Qualification);

    expect($classifier->intent)->toBe('payment_inquiry')
        ->and($classifier->suggestedNextStage)->toBe(ConversationStage::PaymentDiscussion);
});

test('payment inquiry does not regress a booked conversation', function () {
    $interpretation = makeInterpretationService()->interpret('DP-nya sudah saya transfer ya kak');
    $classifier = makeInterpretationService()->toClassifierOutput($interpretation, ConversationStage::Booked);

    expect($classifier->suggestedNextStage)->toBe(ConversationStage::Booked);
});

test('interpret normalizes package recommendation classifier intent into package inquiry taxonomy', function () {
    $classifier = new ClassifierOutput(
        intent: 'package_recommendation',
        sentiment: 'neutral',
        extractedFields: ['service_type' => 'wedding'],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
        currentStage: ConversationStage::PaymentDiscussion,
        suggestedNextStage: ConversationStage::PaymentDiscussion,
        missingCriticalFields: [],
    );

    $result = makeInterpretationService()->interpret('Cocoknya paket yang mana ya?', $classifier);
    $merged = makeInterpretationService()->mergeClassifierOutput($classifier, $result);

    expect($result->canonicalIntent)->toBe('package_inquiry')
        ->and($result->legacyIntent)->toBe('tanya_paket')
        ->and($merged->intent)->toBe('tanya_paket');
});

test('package inquiry does not regress from PaymentDiscussion back to PackageRecommendation', function () {
    $interpretation = makeInterpretationService()->interpret('Harga paket gold berapa ya?');
    $classifier = makeInterpretationService()->toClassifierOutput($interpretation, ConversationStage::PaymentDiscussion);

    expect($classifier->suggestedNextStage)->toBe(ConversationStage::PaymentDiscussion);
});

test('buying signal moves conversation into Closing stage', function () {
    $interpretation = makeInterpretationService()->interpret('Oke aku ambil paket gold aja untuk wedding di Bandung');
    $classifier = makeInterpretationService()->toClassifierOutput($interpretation, ConversationStage::PackageRecommendation);

    expect($classifier->intent)->toBe('ready_to_book')
        ->and($classifier->suggestedNextStage)->toBe(ConversationStage::Closing);
});
