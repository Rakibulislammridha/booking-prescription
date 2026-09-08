<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `SI-2026-000123` from the Postgres sequence `public.subscription_invoice_seq` (SCHEMA §2.5).
 *
 * A sequence, not `max(number) + 1`: `nextval` is non-transactional, so two invoices generated at the same
 * instant get different numbers even when one of the transactions later rolls back. A gap in an invoice series is
 * an accounting curiosity; a duplicate number is an accounting incident.
 */
final class InvoiceNumber
{
    public static function next(?CarbonImmutable $at = null): string
    {
        $sequence = (int) DB::connection('pgsql')->scalar("select nextval('public.subscription_invoice_seq')");

        return sprintf('SI-%s-%06d', ($at ?? CarbonImmutable::now())->format('Y'), $sequence);
    }
}
