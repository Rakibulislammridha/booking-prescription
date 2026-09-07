<?php

declare(strict_types=1);

namespace Tests\Concurrency\Billing;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/**
 * CONVENTIONS §6.5 for money. The transaction wrapper cannot prove locking, so these run in real parallel
 * processes against committed state.
 *
 * Two properties, both worth a clinic's trust:
 *   1. eight devices replaying the SAME offline receipt at the same instant produce exactly ONE payment;
 *   2. eight desks collecting concurrently can never together collect more than the bill.
 */
#[Group('concurrency')]
final class CollectPaymentConcurrencyTest extends TestCase
{
    use BillingFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    protected function tearDown(): void
    {
        $this->truncateTenantTables('a', [
            'refunds', 'payments', 'coupon_redemptions', 'discounts', 'invoice_items', 'invoices', 'cash_shifts',
            'doctor_revenue_shares', 'coupons', 'appointments', 'serial_events', 'serials', 'serial_pools',
            'serial_blocks', 'session_instances', 'patients', 'audit_logs',
        ]);
        parent::tearDown();
    }

    public function test_parallel_replays_of_one_receipt_charge_the_patient_exactly_once(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $receipt = 'D9-000777';

        $results = ProcessPool::run(workers: 8, command: [
            'php', 'artisan', 'billing:hammer',
            '--tenant=9001',
            "--appointment={$booked->appointment->id}",
            "--receipt={$receipt}",
            '--amount=80000',
            '--count=6',
            '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 180);

        $payments = Payment::query()->where('invoice_id', $invoice->id)->get();

        $this->assertCount(1, $payments, '48 concurrent replays of one slip must leave exactly one payment');
        $this->assertSame(80000, (int) $payments->sum('amount_paisa'));
        $this->assertSame($receipt, $payments->first()?->receipt_number);
        $this->assertSame(80000, (int) Invoice::query()->whereKey($invoice->id)->value('paid_paisa'));
        $this->assertSame(0, (int) Invoice::query()->whereKey($invoice->id)->value('due_paisa'));
        $this->assertSame(0, $results->failed(), 'no worker crashed');
    }

    public function test_parallel_desks_can_never_together_collect_more_than_the_bill(): void
    {
        $booked = $this->book(mobile: '01710000222', name: 'Parallel Desk');
        $invoice = app(IssueInvoice::class)->handle(
            Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail(),
            Actor::system(),
        );

        // Eight workers each try to take ৳200 five times: ৳8,000 of attempts against an ৳800 bill.
        ProcessPool::run(workers: 8, command: [
            'php', 'artisan', 'billing:hammer',
            '--tenant=9001',
            "--invoice={$invoice->id}",
            '--amount=20000',
            '--count=5',
            '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 180);

        $collected = (int) Payment::query()->where('invoice_id', $invoice->id)->sum('amount_paisa');
        $paid = (int) Invoice::query()->whereKey($invoice->id)->value('paid_paisa');
        $due = (int) Invoice::query()->whereKey($invoice->id)->value('due_paisa');

        $this->assertSame(80000, $collected, 'the bill is collected in full and not a paisa more');
        $this->assertSame(80000, $paid);
        $this->assertSame(0, $due);
        $this->assertSame(4, Payment::query()->where('invoice_id', $invoice->id)->count(), 'four ৳200 slices, whoever won them');
    }
}
