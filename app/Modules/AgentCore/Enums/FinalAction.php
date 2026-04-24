<?php

namespace App\Modules\AgentCore\Enums;

enum FinalAction: string
{
    case RespondToUser = 'respond_to_user';
    case ReplyWithPackageDetails = 'reply_with_package_details';
    case ReplyWithPriceDetails = 'reply_with_price_details';
    case ReplyWithPackageComparison = 'reply_with_package_comparison';
    case AskForBookingField = 'ask_for_booking_field';
    case GuideToBooking = 'guide_to_booking';
    case RequestHumanHandoff = 'request_human_handoff';
    case ReplyWithOptOut = 'reply_with_opt_out';
    case ReplyWithFallback = 'reply_with_fallback';
    case DoNotReply = 'do_not_reply';

    public static function coerce(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
