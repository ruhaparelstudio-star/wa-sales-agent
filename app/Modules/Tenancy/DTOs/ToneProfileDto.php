<?php

namespace App\Modules\Tenancy\DTOs;

use App\Modules\Tenancy\Enums\PersonaStyle;
use App\Modules\Tenancy\Enums\ToneFormality;
use App\Modules\Tenancy\Models\Tenant;

final class ToneProfileDto
{
    /**
     * @param  list<string>  $forbiddenPhrases
     */
    public function __construct(
        public readonly string $languagePrimary,
        public readonly ToneFormality $formality,
        public readonly PersonaStyle $personaStyle,
        public readonly array $forbiddenPhrases,
    ) {}

    public static function default(): self
    {
        return new self(
            languagePrimary:  'id',
            formality:        ToneFormality::SemiCasual,
            personaStyle:     PersonaStyle::Consultative,
            forbiddenPhrases: [],
        );
    }

    public static function fromTenant(Tenant $tenant): self
    {
        $profile = $tenant->tone_profile ?? [];

        if (! is_array($profile) || $profile === []) {
            return self::default();
        }

        $formalityRaw = $profile['formality'] ?? null;
        $personaRaw   = $profile['persona_style'] ?? null;

        $formality = ToneFormality::tryFrom((string) $formalityRaw) ?? ToneFormality::SemiCasual;
        $persona   = PersonaStyle::tryFrom((string) $personaRaw) ?? PersonaStyle::Consultative;

        $forbidden = $profile['forbidden_phrases'] ?? [];
        if (! is_array($forbidden)) {
            $forbidden = [];
        }

        $forbidden = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $forbidden,
        ), static fn (string $v): bool => $v !== ''));

        $language = is_string($profile['language_primary'] ?? null) && $profile['language_primary'] !== ''
            ? (string) $profile['language_primary']
            : 'id';

        return new self(
            languagePrimary:  $language,
            formality:        $formality,
            personaStyle:     $persona,
            forbiddenPhrases: $forbidden,
        );
    }
}
