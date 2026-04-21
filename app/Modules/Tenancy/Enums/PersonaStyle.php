<?php

namespace App\Modules\Tenancy\Enums;

enum PersonaStyle: string
{
    case Consultative = 'consultative';
    case Warm         = 'warm';
    case Direct       = 'direct';

    public function directive(): string
    {
        return match ($this) {
            self::Consultative => 'Gaya konsultatif: tanyakan kebutuhan dulu, beri rekomendasi berdasarkan informasi yang user berikan. Jangan langsung jualan.',
            self::Warm         => 'Gaya hangat dan empatik: akui perasaan user, gunakan bahasa apresiatif, jaga ritme percakapan tetap personal.',
            self::Direct       => 'Gaya langsung dan ringkas: to the point, fokus pada kebutuhan user tanpa banyak basa-basi, tetap sopan.',
        };
    }
}
