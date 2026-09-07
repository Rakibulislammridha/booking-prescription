<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum SslStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Issued = 'issued';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
