<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum SubscriptionInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
