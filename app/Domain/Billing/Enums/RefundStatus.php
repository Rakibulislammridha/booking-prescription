<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** refunds.status (SCHEMA §3.5). Money leaves the books only at `processed`. */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Counts against payments.refunded_paisa and invoices.paid_paisa. */
    public function reducesPaid(): bool
    {
        return $this === self::Processed;
    }

    /** Still holds a claim on the payment (blocks a second refund of the same money). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }
}
