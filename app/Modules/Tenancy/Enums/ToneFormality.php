<?php

namespace App\Modules\Tenancy\Enums;

enum ToneFormality: string
{
    case Formal     = 'formal';
    case SemiCasual = 'semi_casual';
    case Casual     = 'casual';

    public function directive(): string
    {
        return match ($this) {
            self::Formal     => 'Gunakan sapaan formal ("Bapak/Ibu", "Anda"). Hindari slang dan singkatan. Kalimat lengkap.',
            self::SemiCasual => 'Gunakan sapaan santai tapi sopan ("kakak", "kamu"). Hindari slang berlebihan. Kalimat natural.',
            self::Casual     => 'Santai, seperti chat teman ("kamu", sapaan ringan). Boleh singkatan umum, tetap jaga kesopanan.',
        };
    }
}
