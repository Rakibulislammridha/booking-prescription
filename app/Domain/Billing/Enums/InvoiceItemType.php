<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** invoice_items.type (SCHEMA §3.5). */
enum InvoiceItemType: string
{
    case Consultation = 'consultation';
    case Followup = 'followup';
    case Investigation = 'investigation';
    case Telemedicine = 'telemedicine';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
