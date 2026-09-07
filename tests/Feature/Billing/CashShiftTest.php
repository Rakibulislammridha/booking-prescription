<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\CloseCashShift;
use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Actions\OpenCashShift;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\CashShiftStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Exceptions\ShiftAlreadyOpen;
use App\Domain\Billing\Exceptions\ShiftNotOpen;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\CashShift;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** The cash drawer: one open shift per user, expected-vs-counted reconciliation, and a recorded mismatch. */
final class CashShiftTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_only_one_shift_can_be_open_per_user(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        app(OpenCashShift::class)->handle($user, $this->mainBranch(), 100000, $this->staffActor());

        $this->expectException(ShiftAlreadyOpen::class);
        app(OpenCashShift::class)->handle($user, $this->mainBranch(), 50000, $this->staffActor());
    }

    public function test_closing_reconciles_the_float_the_cash_taken_and_the_cash_refunded(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $shift = app(OpenCashShift::class)->handle($user, $this->mainBranch(), 100000, $this->staffActor());

        $this->assertSame($shift->id, app(CurrentShift::class)->idForUser($user->id));

        // Two cash payments, one card payment (card never touches the drawer), one cash refund out of it.
        $paid = [];

        foreach ([['cash-1', 80000, PaymentMethod::Cash], ['cash-2', 50000, PaymentMethod::Cash], ['card-1', 80000, PaymentMethod::Card]] as [$key, $amount, $method]) {
            $booked = $this->book(mobile: '0171000'.random_int(1000, 9999), name: 'Patient '.$key);
            $invoice = $this->issuedInvoiceFor($booked->appointment);
            $paid[$key] = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
                method: $method, amountPaisa: min($amount, $invoice->due_paisa), idempotencyKey: $key, cashShiftId: $shift->id,
            ), $this->staffActor());
        }

        app(IssueRefund::class)->handle($paid['cash-2']->payment, new RefundRequest(
            reasonCode: RefundReason::Duplicate, amountPaisa: 20000, method: PaymentMethod::Cash,
        ), $this->staffActor());

        $cashIn = $paid['cash-1']->payment->amount_paisa + $paid['cash-2']->payment->amount_paisa;
        $expected = 100000 + $cashIn - 20000;

        // The receptionist counts exactly what should be there.
        $closed = app(CloseCashShift::class)->handle($shift, $expected, $this->staffActor(), 'end of morning');

        $this->assertSame(CashShiftStatus::Closed, $closed->status);
        $this->assertSame($expected, $closed->expected_cash_paisa);
        $this->assertSame($expected, $closed->counted_cash_paisa);
        $this->assertSame(0, $closed->variance_paisa);
        $this->assertSame($paid['card-1']->payment->amount_paisa, $closed->card_total_paisa, 'card totals are informational');
        $this->assertSame(0, $closed->mobile_money_total_paisa);
        $this->assertAudited(AuditAction::Update, $closed, ['event' => 'shift_closed']);

        $this->expectException(ShiftNotOpen::class);
        app(CloseCashShift::class)->handle($closed, $expected, $this->staffActor());
    }

    public function test_a_shortfall_is_recorded_as_a_negative_variance_and_never_rounded_away(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $shift = app(OpenCashShift::class)->handle($user, $this->mainBranch(), 0, $this->staffActor());

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'short-1', cashShiftId: $shift->id,
        ), $this->staffActor());

        // ৳50 short in the drawer.
        $closed = app(CloseCashShift::class)->handle($shift, 75000, $this->staffActor(), 'short');

        $this->assertSame(80000, $closed->expected_cash_paisa);
        $this->assertSame(75000, $closed->counted_cash_paisa);
        $this->assertSame(-5000, $closed->variance_paisa);

        // An overage is just as visible.
        $second = app(OpenCashShift::class)->handle($user, $this->mainBranch(), 0, $this->staffActor());
        $overClosed = app(CloseCashShift::class)->handle($second, 300, $this->staffActor());
        $this->assertSame(300, $overClosed->variance_paisa);
    }

    public function test_the_shift_screen_shows_the_open_drawer_and_its_live_totals(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        app(OpenCashShift::class)->handle($user, $this->mainBranch(), 100000, $this->staffActor());

        $this->get(route('panel.billing.shift.index', [], false))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Shift')
                ->where('open_shift.status', 'open')
                ->where('live_totals.expected_cash_paisa', 100000));
    }

    public function test_cash_shifts_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('cash_shifts', function (): void {
            $user = $this->actingAsStaff(Role::Receptionist);
            CashShift::factory()->create(['user_id' => $user->id, 'branch_id' => $this->mainBranch()->id]);
        });
    }
}
