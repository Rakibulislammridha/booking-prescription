<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Billing\Queries\CollectionReportQuery;
use App\Domain\Billing\Queries\RevenueShareReportQuery;
use App\Domain\Reports\Data\ReportFilters;

/**
 * Revenue by doctor, branch and payment method (BRIEF §5.L, third line).
 *
 * This class deliberately owns NO SQL. Billing exposed `CollectionReportQuery` and `RevenueShareReportQuery` as
 * query objects precisely so the Reports module would reuse them (their own docblocks say so), and money is the
 * one place where two implementations of "collected" is not a duplication but a future discrepancy: the panel's
 * billing screen and the owner's dashboard must never quote different figures for the same day.
 *
 * What Billing's definitions mean, restated here because the Reports page footnotes them:
 *  - collection = settled payments MINUS processed refunds, grouped on the clinic-local day;
 *  - a payment counts on `paid_at`, a refund on `processed_at`, so a refund of last month's payment reduces
 *    THIS month — the report matches the cash drawer, not the invoice;
 *  - commission reads the FROZEN `invoice_items.doctor_share_paisa` written at issue, never the current rule,
 *    so re-running an old month after a rule change returns the old month's numbers;
 *  - `collected` in the commission table is apportioned by the invoice's paid ratio: a half-paid bill has half
 *    its commission earned.
 *
 * The collection report is also the source of the dashboard's money tile, so there is exactly one definition of
 * "collected today" in the product.
 */
final class RevenueQuery
{
    public function __construct(
        private readonly CollectionReportQuery $collection,
        private readonly RevenueShareReportQuery $shares,
    ) {}

    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        return [
            'collection' => $this->collection->summary($filters->from, $filters->to, $filters->billingFilters()),
            'commission' => $this->shares->summary($filters->from, $filters->to, $filters->billingFilters()),
        ];
    }

    /** @return array{gross_paisa: int, refunds_paisa: int, net_paisa: int, payment_count: int, invoice_count: int} */
    public function totals(ReportFilters $filters): array
    {
        return $this->collection->totals($filters->startUtc(), $filters->endInclusiveUtc(), $filters->billingFilters());
    }
}
