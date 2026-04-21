<?php

namespace App\Modules\Subscription\Enums;

enum SubscriptionStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active         = 'active';
    case GracePeriod    = 'grace_period';
    case Expired        = 'expired';
    case Suspended      = 'suspended';
}
