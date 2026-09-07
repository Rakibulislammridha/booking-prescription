<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** doctor_revenue_shares.item_type (SCHEMA §3.5): the invoice item types plus the `all` catch-all. */
enum RevenueShareItemType: string
{
    case Consultation = 'consultation';
    case Followup = 'followup';
    case Investigation = 'investigation';
    case Telemedicine = 'telemedicine';
    case All = 'all';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function matches(InvoiceItemType $item): bool
    {
        return $this === self::All || $this->value === $item->value;
    }
}
