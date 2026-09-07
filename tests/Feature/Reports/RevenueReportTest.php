<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\RevenueQuery;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * Revenue delegates to Billing's own query objects, so this class proves the SEAM rather than re-testing
 * Billing: the range, the filters and the refund rule arrive intact, and a refund lands on the day it was
 * PROCESSED — a refund of March's payment in April reduces April, which is what the cash drawer shows.
 */
final class RevenueReportTest extends TestCase
{
    use ReportFixture;

    private RevenueQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Accountant);
        $this->seedReportFixture();
        $this->query = app(RevenueQuery::class);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_collection_is_settled_payments_minus_processed_refunds_on_the_local_day(): void
    {
        $this->invoiceWithPayment($this->rahman->id, 80000, PaymentMethod::Cash, '2026-03-01 09:00');
        $paid = $this->invoiceWithPayment($this->rahman->id, 80000, PaymentMethod::Bkash, '2026-03-01 09:30');
        $this->refund($paid, 30000, '2026-03-03 11:00');

        $march = ReportFilters::forDays('2026-03-01', '2026-03-10');
        $totals = $this->query->totals($march);

        $this->assertSame(160000, $totals['gross_paisa']);
        $this->assertSame(30000, $totals['refunds_paisa']);
        $this->assertSame(130000, $totals['net_paisa']);
        $this->assertSame(2, $totals['payment_count']);

        $summary = $this->query->summary($march);
        $byDay = $this->rows($summary['collection']['by_day'])->keyBy('date');
        $this->assertSame(160000, $byDay['2026-03-01']['gross_paisa']);
        // Billing's day series is driven by the days money CAME IN: 3 March took no payment, so the refund
        // that was processed on it has no row of its own. The refund is still in the totals above, which is
        // where the clinic reads its net. (Raised with Billing; not silently patched here, because a second
        // implementation of "collected" is exactly how two screens start disagreeing.)
        $this->assertArrayNotHasKey('2026-03-03', $byDay->all());
        $this->assertSame(130000, $summary['collection']['totals']['net_paisa']);

        $byMethod = $this->rows($summary['collection']['by_method'])->keyBy('key');
        $this->assertSame(80000, $byMethod['cash']['net_paisa']);
        $this->assertSame(50000, $byMethod['bkash']['net_paisa']);
    }

    public function test_a_refund_processed_after_the_range_does_not_reduce_it(): void
    {
        $paid = $this->invoiceWithPayment($this->rahman->id, 50000, PaymentMethod::Cash, '2026-03-01 09:00');
        $this->refund($paid, 50000, '2026-04-02 10:00');

        $march = $this->query->totals(ReportFilters::forDays('2026-03-01', '2026-03-31'));
        $this->assertSame(50000, $march['gross_paisa']);
        $this->assertSame(0, $march['refunds_paisa']);
        $this->assertSame(50000, $march['net_paisa']);

        $april = $this->query->totals(ReportFilters::forDays('2026-04-01', '2026-04-30'));
        $this->assertSame(0, $april['gross_paisa']);
        $this->assertSame(50000, $april['refunds_paisa']);
        $this->assertSame(-50000, $april['net_paisa']);
    }

    public function test_the_doctor_filter_reaches_billings_query(): void
    {
        $this->invoiceWithPayment($this->rahman->id, 80000, PaymentMethod::Cash, '2026-03-01 09:00');
        $this->invoiceWithPayment($this->sultana->id, 60000, PaymentMethod::Cash, '2026-03-02 09:00');

        $march = ReportFilters::forDays('2026-03-01', '2026-03-10');

        $this->assertSame(140000, $this->query->totals($march)['net_paisa']);
        $this->assertSame(80000, $this->query->totals($march->withDoctor($this->rahman->id))['net_paisa']);
        $this->assertSame(60000, $this->query->totals($march->withDoctor($this->sultana->id))['net_paisa']);
    }

    private function invoiceWithPayment(int $doctorId, int $paisa, PaymentMethod $method, string $localPaidAt): Invoice
    {
        $invoice = Invoice::factory()->create([
            'patient_id' => $this->patients['P1']->id,
            'doctor_id' => $doctorId,
            'branch_id' => $this->main->id,
            'status' => InvoiceStatus::Paid,
            'subtotal_paisa' => $paisa,
            'total_paisa' => $paisa,
            'paid_paisa' => $paisa,
            'issued_at' => $this->utc($localPaidAt),
            'paid_at' => $this->utc($localPaidAt),
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            'method' => $method,
            'status' => PaymentTxnStatus::Succeeded,
            'amount_paisa' => $paisa,
            'gateway' => $method->value === 'bkash' ? PaymentGateway::Bkash : null,
            'paid_at' => $this->utc($localPaidAt),
        ]);

        return $invoice;
    }

    private function refund(Invoice $invoice, int $paisa, string $localProcessedAt): Refund
    {
        $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return Refund::factory()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_paisa' => $paisa,
            'method' => $payment->method,
            'status' => RefundStatus::Processed,
            'processed_at' => $this->utc($localProcessedAt),
        ]);
    }
}
