<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum SubscriptionPaymentMethod: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case SslCommerz = 'sslcommerz';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Manual = 'manual';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
