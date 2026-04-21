<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\DTOs\ToneProfileDto;
use App\Modules\Tenancy\Enums\PersonaStyle;
use App\Modules\Tenancy\Enums\ToneFormality;
use App\Modules\Tenancy\Models\Tenant;

class UpdateToneProfileAction
{
    public function execute(Tenant $tenant, ToneProfileDto $dto): Tenant
    {
        $tenant->forceFill([
            'tone_profile' => [
                'language_primary'  => $dto->languagePrimary,
                'formality'         => $dto->formality->value,
                'persona_style'     => $dto->personaStyle->value,
                'forbidden_phrases' => array_values($dto->forbiddenPhrases),
            ],
        ])->save();

        return $tenant->refresh();
    }

    /**
     * Convenience builder used by UI inputs.
     *
     * @param  list<string>|string  $forbiddenPhrases  list or newline-separated string
     */
    public function executeFromRaw(
        Tenant $tenant,
        string $languagePrimary,
        string $formality,
        string $personaStyle,
        array|string $forbiddenPhrases,
    ): Tenant {
        if (is_string($forbiddenPhrases)) {
            $forbiddenPhrases = array_values(array_filter(array_map(
                static fn (string $v): string => trim($v),
                preg_split('/\r?\n/', $forbiddenPhrases) ?: [],
            ), static fn (string $v): bool => $v !== ''));
        }

        return $this->execute(
            $tenant,
            new ToneProfileDto(
                languagePrimary:  $languagePrimary !== '' ? $languagePrimary : 'id',
                formality:        ToneFormality::tryFrom($formality) ?? ToneFormality::SemiCasual,
                personaStyle:     PersonaStyle::tryFrom($personaStyle) ?? PersonaStyle::Consultative,
                forbiddenPhrases: $forbiddenPhrases,
            ),
        );
    }
}
