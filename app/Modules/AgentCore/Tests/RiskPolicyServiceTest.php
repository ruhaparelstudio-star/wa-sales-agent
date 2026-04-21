<?php

use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\Services\RiskPolicyService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;


test('negative sentiment raises risk score', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'risk_score' => 0]);

    $classifier = new ClassifierOutput(
        intent: 'complaint',
        sentiment: 'negative',
        extractedFields: [],
        needsHandoff: true,
        handoffReason: 'complaint',
        confidence: 0.9,
    );

    $score = (new RiskPolicyService())->calculateRisk($lead, $classifier);

    expect($score)->toBeGreaterThanOrEqual(55) // negative(+30) + complaint(+25)
        ->and($lead->fresh()->risk_score)->toBe($score);
});

test('opt_out keyword pushes score to high risk', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'risk_score' => 0]);

    $classifier = new ClassifierOutput(
        intent: 'opt_out',
        sentiment: 'negative',
        extractedFields: [],
        needsHandoff: true,
        handoffReason: 'opt_out',
        confidence: 0.95,
    );

    $service = new RiskPolicyService();
    $score = $service->calculateRisk($lead, $classifier, 'stop jangan hubungi lagi');

    expect($score)->toBe(100)
        ->and($service->isHighRisk($lead->fresh()))->toBeTrue();
});

test('neutral classifier keeps score low', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $classifier = new ClassifierOutput(
        intent: 'tanya_paket',
        sentiment: 'neutral',
        extractedFields: [],
        needsHandoff: false,
        handoffReason: null,
        confidence: 0.9,
    );

    $score = (new RiskPolicyService())->calculateRisk($lead, $classifier, 'halo saya mau tanya paket');

    expect($score)->toBe(0);
});
