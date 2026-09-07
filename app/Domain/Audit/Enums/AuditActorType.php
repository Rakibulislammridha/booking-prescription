<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditActorType: string
{
    case User = 'user';
    case Patient = 'patient';
    case Device = 'device';
    case System = 'system';
    case SuperAdmin = 'super_admin';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
