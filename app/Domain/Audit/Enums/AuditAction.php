<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Print = 'print';
    case Export = 'export';
    case Reorder = 'reorder';
    case Issue = 'issue';
    case Amend = 'amend';
    case Void = 'void';
    case CheckIn = 'check_in';
    case Transfer = 'transfer';
    case Login = 'login';
    case Logout = 'logout';
    case Download = 'download';
    case Share = 'share';
    case Refund = 'refund';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
