<?php

use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Services\PromptBuilder;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Tenancy\DTOs\ToneProfileDto;
use App\Modules\Tenancy\Enums\PersonaStyle;
use App\Modules\Tenancy\Enums\ToneFormality;
use App\Modules\Tenancy\Models\Tenant;

test('response prompt includes tone profile directives and stage playbook', function () {
    $tenant = Tenant::factory()->create([
        'name'         => 'Vendor ABC',
        'tone_profile' => [
            'language_primary'  => 'id',
            'formality'         => 'formal',
            'persona_style'     => 'consultative',
            'forbidden_phrases' => ['pasti bisa', 'dijamin'],
        ],
    ]);

    $prompt = (new PromptBuilder())->buildSystem(
        $tenant,
        LlmMode::Response,
        ToneProfileDto::fromTenant($tenant),
        ConversationStage::Qualification,
    );

    expect($prompt)
        ->toContain('Vendor ABC')
        ->toContain('TONE:')
        ->toContain('Formalitas:')
        ->toContain('FORBIDDEN PHRASES')
        ->toContain('"pasti bisa"')
        ->toContain('"dijamin"')
        ->toContain('STAGE PLAYBOOK (qualification)')
        ->toContain('SALES INSTINCT:')
        ->toContain('Gunakan RESPONSE PLAN sebagai outline')
        ->toContain('Language mirror')
        ->toContain('Maksimal 2 kalimat pendek.')
        ->toContain('Fokus bantu user maju selangkah')
        ->toContain('Jangan mengulang informasi yang baru saja kamu sampaikan')
        ->toContain('JANGAN bilang admin/tim akan menghubungi')
        ->toContain('Contoh gaya: "Siap, biar aku arahin yang pas.');
});

test('response prompt enforces no-fake-promise rule', function () {
    $tenant = Tenant::factory()->create();

    $prompt = (new PromptBuilder())->buildSystem(
        $tenant,
        LlmMode::Response,
        null,
        ConversationStage::Qualification,
    );

    expect($prompt)
        ->toContain('JANGAN bilang admin/tim akan menghubungi')
        ->toContain('Jangan mengarang fakta, harga, availability, atau janji');
});

test('response prompt enforces no-reask rule for filled slots', function () {
    $tenant = Tenant::factory()->create();

    $prompt = (new PromptBuilder())->buildSystem(
        $tenant,
        LlmMode::Response,
        null,
        ConversationStage::Qualification,
    );

    expect($prompt)->toContain('Jangan tanya ulang slot yang sudah terisi atau ada di already_asked');
});

test('response prompt explicitly prioritizes active pricing focus', function () {
    $tenant = Tenant::factory()->create();

    $prompt = (new PromptBuilder())->buildSystem(
        $tenant,
        LlmMode::Response,
        null,
        ConversationStage::FollowUp,
    );

    expect($prompt)
        ->toContain('Jika ACTIVE USER FOCUS ada, jawab fokus itu dulu')
        ->toContain('Jika ACTIVE USER FOCUS.pricing_focus = price_only: jawab soal harga dulu.')
        ->toContain('Jika ACTIVE USER FOCUS.pricing_focus = package_only: jawab soal isi paket dulu.')
        ->toContain('Jika ACTIVE USER FOCUS.pricing_focus = price_and_package: jawab harga dan isi paket dulu, jangan suruh user memilih lagi.')
        ->toContain('Jika pertanyaan user saat ini tentang paket atau rekomendasi paket, JANGAN membawa DP, pelunasan, atau proses booking kecuali user memang menanyakannya.')
        ->toContain('Jika user meminta rekomendasi paket yang cocok, beri rekomendasi awal yang paling masuk akal dari context, jelaskan alasan singkatnya, lalu tanyakan SATU hal lanjutan yang paling membantu.');
});

test('closing stage playbook forbids regression to broad discovery', function () {
    $tenant = Tenant::factory()->create();

    $prompt = (new PromptBuilder())->buildSystem(
        $tenant,
        LlmMode::Response,
        null,
        ConversationStage::Closing,
    );

    expect($prompt)->toContain('Jangan kembali ke pertanyaan broad discovery');
});

test('stage playbook changes per stage', function () {
    $tenant = Tenant::factory()->create();
    $builder = new PromptBuilder();

    $qualification = $builder->buildSystem($tenant, LlmMode::Response, null, ConversationStage::Qualification);
    $pkg           = $builder->buildSystem($tenant, LlmMode::Response, null, ConversationStage::PackageRecommendation);
    $closing       = $builder->buildSystem($tenant, LlmMode::Response, null, ConversationStage::Closing);

    expect($qualification)->toContain('STAGE PLAYBOOK (qualification)')
        ->and($pkg)->toContain('STAGE PLAYBOOK (package_recommendation)')
        ->and($closing)->toContain('STAGE PLAYBOOK (closing)')
        ->and($qualification)->toContain('Contoh gaya: "Siap, biar aku arahin yang pas.')
        ->and($pkg)->toContain('Contoh gaya: "Dari kebutuhan kamu, paket A paling masuk')
        ->and($closing)->toContain('Contoh gaya: "Kalau ini sudah cocok')
        ->and($qualification)->not->toContain('STAGE PLAYBOOK (closing)');
});

test('classifier prompt includes stage hint fields', function () {
    $tenant = Tenant::factory()->create();

    $prompt = (new PromptBuilder())->buildSystem($tenant, LlmMode::Classifier);

    expect($prompt)
        ->toContain('current_stage')
        ->toContain('suggested_next_stage')
        ->toContain('missing_critical_fields')
        ->toContain('Gunakan HANYA intent yang ada di schema JSON di atas.')
        ->toContain('tetap pakai intent `tanya_paket`, BUKAN `package_recommendation`');
});

test('follow up and evaluation prompts are role specific', function () {
    $tenant = Tenant::factory()->create();
    $builder = new PromptBuilder();

    $followUp = $builder->buildSystem($tenant, LlmMode::FollowUp, null, ConversationStage::FollowUp);
    $evaluation = $builder->buildSystem($tenant, LlmMode::Evaluation);

    expect($followUp)
        ->toContain('sedang mengirim follow-up')
        ->toContain('ATURAN FOLLOW-UP:')
        ->and($evaluation)
        ->toContain('Kamu mengevaluasi kualitas respons sales agent WhatsApp.')
        ->toContain('"score"');
});

test('tone profile falls back to defaults when tenant has no settings', function () {
    $tenant = Tenant::factory()->create(['tone_profile' => null]);

    $dto = ToneProfileDto::fromTenant($tenant);

    expect($dto->languagePrimary)->toBe('id')
        ->and($dto->formality)->toBe(ToneFormality::SemiCasual)
        ->and($dto->personaStyle)->toBe(PersonaStyle::Consultative)
        ->and($dto->forbiddenPhrases)->toBe([]);
});
