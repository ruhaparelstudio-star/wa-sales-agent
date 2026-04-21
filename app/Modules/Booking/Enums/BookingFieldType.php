<?php

namespace App\Modules\Booking\Enums;

enum BookingFieldType: string
{
    case Text     = 'text';
    case Date     = 'date';
    case Number   = 'number';
    case Select   = 'select';
    case Textarea = 'textarea';

    public function hasOptions(): bool
    {
        return $this === self::Select;
    }
}
