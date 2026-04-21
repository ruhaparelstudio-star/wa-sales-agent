<?php

namespace App\Modules\Knowledge\Enums;

enum KnowledgeType: string
{
    case Faq       = 'faq';
    case Package   = 'package';
    case Policy    = 'policy';
    case Portfolio = 'portfolio';
    case Objection = 'objection';

    public function label(): string
    {
        return match ($this) {
            self::Faq       => 'FAQ',
            self::Package   => 'Paket',
            self::Policy    => 'Kebijakan',
            self::Portfolio => 'Portfolio',
            self::Objection => 'Penanganan Keberatan',
        };
    }
}
