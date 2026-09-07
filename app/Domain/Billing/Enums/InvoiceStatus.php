<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** invoices.status (SCHEMA §3.5). A `void` invoice is never edited — corrections are new rows. */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';
    case Refunded = 'refunded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Money may still be taken against the invoice. */
    public function isPayable(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /** Frozen: totals and lines may no longer change (adjust through discounts/refunds/new invoices). */
    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }
}
