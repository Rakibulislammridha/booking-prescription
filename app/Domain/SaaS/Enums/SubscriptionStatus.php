<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
