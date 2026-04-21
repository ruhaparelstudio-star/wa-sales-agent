<?php

namespace App\Modules\Conversations\Enums;

enum HandoffReason: string
{
    case AvailabilityCheck = 'availability_check';
    case CustomPackage = 'custom_package';
    case ReadyToBook = 'ready_to_book';
    case PaymentProof = 'payment_proof';
    case Complaint = 'complaint';
    case NegativeSentiment = 'negative_sentiment';
    case Other = 'other';

    public function label(): string
    {
        return match($this) {
            self::AvailabilityCheck => 'Cek Ketersediaan',
            self::CustomPackage     => 'Paket Custom',
            self::ReadyToBook       => 'Siap Booking',
            self::PaymentProof      => 'Bukti Pembayaran',
            self::Complaint         => 'Komplain',
            self::NegativeSentiment => 'Sentimen Negatif',
            self::Other             => 'Lainnya',
        };
    }
}
