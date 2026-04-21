<?php

namespace App\Modules\Conversations\Enums;

enum ConversationStage: string
{
    case NewLead              = 'new_lead';
    case Qualification        = 'qualification';
    case NeedsDiscovery       = 'needs_discovery';
    case PackageRecommendation = 'package_recommendation';
    case ObjectionHandling    = 'objection_handling';
    case PaymentDiscussion    = 'payment_discussion';
    case Closing              = 'closing';
    case Booked               = 'booked';
    case FollowUp             = 'follow_up';
    case HandoffToHuman       = 'handoff_to_human';
    case Closed               = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::NewLead             => 'New Lead',
            self::Qualification       => 'Qualification',
            self::NeedsDiscovery      => 'Needs Discovery',
            self::PackageRecommendation => 'Package Recommendation',
            self::ObjectionHandling   => 'Objection Handling',
            self::PaymentDiscussion   => 'Payment Discussion',
            self::Closing             => 'Closing',
            self::Booked              => 'Booked',
            self::FollowUp            => 'Follow Up',
            self::HandoffToHuman      => 'Handoff To Human',
            self::Closed              => 'Closed',
        };
    }

    /**
     * @return list<ConversationStage>
     */
    public static function classifierStages(): array
    {
        return [
            self::NewLead,
            self::Qualification,
            self::NeedsDiscovery,
            self::PackageRecommendation,
            self::ObjectionHandling,
            self::PaymentDiscussion,
            self::Closing,
            self::Booked,
            self::FollowUp,
            self::HandoffToHuman,
        ];
    }

    public static function coerce(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized) ?? match ($normalized) {
            'greeting' => self::NewLead,
            'discovery' => self::Qualification,
            'needs_matching' => self::NeedsDiscovery,
            'package_presentation' => self::PackageRecommendation,
            'soft_close' => self::Closing,
            'handoff' => self::HandoffToHuman,
            default => null,
        };
    }

    /**
     * Stages yang masih aktif untuk automation. Handoff & Closed tidak.
     */
    public function isAutomatable(): bool
    {
        return ! in_array($this, [self::Booked, self::HandoffToHuman, self::Closed], true);
    }

    /**
     * @return list<ConversationStage>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::NewLead => [self::Qualification, self::HandoffToHuman],
            self::Qualification => [
                self::NeedsDiscovery,
                self::PackageRecommendation,
                self::PaymentDiscussion,
                self::ObjectionHandling,
                self::HandoffToHuman,
            ],
            self::NeedsDiscovery => [
                self::PackageRecommendation,
                self::PaymentDiscussion,
                self::ObjectionHandling,
                self::HandoffToHuman,
            ],
            self::PackageRecommendation => [
                self::ObjectionHandling,
                self::PaymentDiscussion,
                self::Closing,
                self::FollowUp,
                self::HandoffToHuman,
            ],
            self::ObjectionHandling => [
                self::NeedsDiscovery,
                self::PackageRecommendation,
                self::PaymentDiscussion,
                self::Closing,
                self::FollowUp,
                self::HandoffToHuman,
            ],
            self::PaymentDiscussion => [
                self::PackageRecommendation,
                self::ObjectionHandling,
                self::Closing,
                self::Booked,
                self::HandoffToHuman,
            ],
            self::Closing => [
                self::ObjectionHandling,
                self::PaymentDiscussion,
                self::Booked,
                self::FollowUp,
                self::HandoffToHuman,
            ],
            self::Booked => [self::FollowUp, self::Closed],
            self::FollowUp => [
                self::Qualification,
                self::NeedsDiscovery,
                self::PackageRecommendation,
                self::PaymentDiscussion,
                self::Closing,
                self::HandoffToHuman,
                self::Closed,
            ],
            self::HandoffToHuman => [
                self::Qualification,
                self::NeedsDiscovery,
                self::PackageRecommendation,
                self::ObjectionHandling,
                self::PaymentDiscussion,
                self::Closing,
                self::Booked,
                self::FollowUp,
                self::Closed,
            ],
            self::Closed              => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, $this->allowedNext(), true);
    }
}
