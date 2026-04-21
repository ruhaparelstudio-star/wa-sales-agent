<?php

namespace App\Modules\Leads\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Interested = 'interested';
    case Hot = 'hot';
    case ReadyForHuman = 'ready_for_human';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function label(): string
    {
        return match($this) {
            self::New           => 'Baru',
            self::Qualified     => 'Qualified',
            self::Interested    => 'Tertarik',
            self::Hot           => 'Hot Lead',
            self::ReadyForHuman => 'Siap Ditangani',
            self::ClosedWon     => 'Closing',
            self::ClosedLost    => 'Tidak Jadi',
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::ClosedWon, self::ClosedLost]);
    }

    public function requiresHandoff(): bool
    {
        return in_array($this, [self::Hot, self::ReadyForHuman]);
    }
}
