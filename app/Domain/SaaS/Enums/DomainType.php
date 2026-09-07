<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
