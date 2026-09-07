<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** payments.status (SCHEMA §3.5). Only `succeeded`/`partially_refunded`/`refunded` count towards paid_paisa. */
enum PaymentTxnStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** The money arrived (a refund is recorded separately and subtracted, never by deleting the payment). */
    public function isSettled(): bool
    {
        return in_array($this, [self::Succeeded, self::Refunded, self::PartiallyRefunded], true);
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
