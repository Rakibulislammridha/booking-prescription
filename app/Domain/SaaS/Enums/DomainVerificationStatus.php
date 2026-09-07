<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum DomainVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
