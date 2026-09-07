<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Exceptions\RefundExceedsPayment;
use App\Domain\Billing\Exceptions\RefundsAreOnlineOnly;
use App\Domain\Billing\Services\BillingRefundProcessor;
use App\Domain\Billing\Services\RefundEligibility;
use App\Domain\Booking\Actions\CancelAppointment;
use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Refund;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** Refund eligibility per reason and cutoff, partial refunds, the audit trail, and the offline prohibition. */
final class RefundTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Accountant);
    }

    public function test_billing_owns_the_refund_processor_seam(): void
    {
        $this->assertInstanceOf(BillingRefundProcessor::class, app(RefundProcessor::class));
    }

    public function test_a_partial_refund_leaves_the_rest_refundable_and_never_exceeds_the_payment(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'refund-base',
        ), $this->staffActor());

        $first = app(IssueRefund::class)->handle($paid->payment, new RefundRequest(
            reasonCode: RefundReason::ServiceNotRendered, amountPaisa: 30000, note: 'left before the call',
        ), $this->staffActor());

        $this->assertSame(RefundStatus::Processed, $first->status);
        $payment = $paid->payment->refresh();
        $this->assertSame(30000, $payment->refunded_paisa);
        $this->assertSame(PaymentTxnStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(50000, $invoice->refresh()->paid_paisa, 'the invoice balance is recomputed, not decremented');
        $this->assertSame(30000, $invoice->due_paisa);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        // A second refund may take only what is left.
        try {
            app(IssueRefund::class)->handle($payment, new RefundRequest(reasonCode: RefundReason::Goodwill, amountPaisa: 60000), $this->staffActor());
            $this->fail('a refund larger than the remaining payment must be refused');
        } catch (RefundExceedsPayment $e) {
            $this->assertSame('billing.refund_exceeds_payment', $e->code());
        }

        $rest = app(IssueRefund::class)->handle($payment->refresh(), new RefundRequest(reasonCode: RefundReason::Goodwill), $this->staffActor());
        $this->assertSame(50000, $rest->amount_paisa, 'null amount means everything still refundable');
        $this->assertSame(PaymentTxnStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_paisa);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->status);
        $this->assertSame(PaymentStatus::Refunded, $booked->appointment->refresh()->payment_status);

        $this->assertAudited(AuditAction::Refund, $rest);
    }

    public function test_a_pending_refund_holds_its_claim_so_two_halves_cannot_exceed_the_whole(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'claim-base',
        ), $this->staffActor());

        app(IssueRefund::class)->handle($paid->payment, new RefundRequest(
            reasonCode: RefundReason::PatientCancelled, amountPaisa: 60000, autoProcess: false,
        ), $this->staffActor());

        $this->expectException(RefundExceedsPayment::class);
        app(IssueRefund::class)->handle($paid->payment->refresh(), new RefundRequest(
            reasonCode: RefundReason::Goodwill, amountPaisa: 30000,
        ), $this->staffActor());
    }

    public function test_a_reception_device_may_never_create_a_refund(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'device-refund',
        ), $this->staffActor());

        $this->expectException(RefundsAreOnlineOnly::class);
        app(IssueRefund::class)->handle($paid->payment, new RefundRequest(reasonCode: RefundReason::Goodwill), new Actor(deviceId: 7, source: 'offline_replay'));
    }

    public function test_eligibility_follows_the_cancellation_reason_and_the_cutoff_setting(): void
    {
        app(Settings::class)->set('serial.cancel_cutoff_minutes', 60);
        $booked = $this->book();
        $eligibility = app(RefundEligibility::class);

        // The clinic cancelled: always refundable, whatever the clock says.
        $clinic = $eligibility->forCancellation($booked->appointment, CancelReason::DoctorUnavailable, cancelledByPatient: true);
        $this->assertTrue($clinic->eligible);
        $this->assertSame(RefundReason::DoctorAbsent, $clinic->reasonCode);

        $sessionCancelled = $eligibility->forCancellation($booked->appointment, CancelReason::SessionCancelled, cancelledByPatient: true);
        $this->assertTrue($sessionCancelled->eligible);

        // Staff cancelled on the patient's behalf: still refundable.
        $staff = $eligibility->forCancellation($booked->appointment, CancelReason::PatientRequest, cancelledByPatient: false);
        $this->assertTrue($staff->eligible);

        // The patient cancelled after the session started — inside the cutoff, so no automatic refund.
        $this->travelTo(now()->addHours(6));
        $late = $eligibility->forCancellation($booked->appointment, CancelReason::PatientRequest, cancelledByPatient: true);
        $this->assertFalse($late->eligible);
        $this->assertSame(RefundReason::PatientCancelled, $late->reasonCode);
        $this->travelBack();

        // A no-show keeps the fee; a session the clinic never opened does not.
        $this->assertFalse($eligibility->forNoShow($booked->appointment, 'auto')->eligible);
        $this->assertTrue($eligibility->forNoShow($booked->appointment, 'session_closed')->eligible);
    }

    public function test_cancelling_a_paid_booking_raises_a_pending_refund_a_human_must_approve(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'cancel-base',
        ), $this->staffActor());

        $result = app(CancelAppointment::class)->handle($booked->appointment->refresh(), CancelReason::DoctorUnavailable, $this->staffActor());

        $this->assertTrue($result['refund_eligible']);
        $this->assertSame('pending', $result['refund']['status']);
        $this->assertSame(80000, $result['refund']['amount_paisa']);

        $refund = Refund::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(RefundReason::DoctorAbsent, $refund->reason_code);
        $this->assertSame(80000, $invoice->refresh()->paid_paisa, 'a pending refund moves no money');

        // Now the accountant pays it out.
        $this->actingAsStaff(Role::Accountant);
        app(IssueRefund::class)->process($refund, $this->staffActor());

        $this->assertSame(RefundStatus::Processed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_paisa);
    }

    public function test_cancelling_an_unpaid_booking_voids_the_bill_instead_of_leaving_a_due(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        app(CancelAppointment::class)->handle($booked->appointment->refresh(), CancelReason::PatientRequest, $this->staffActor());

        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);
        $this->assertNotNull($invoice->voided_at);
        $this->assertSame(0, Refund::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_refunds_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('refunds', function (): void {
            $this->actingAsStaff(Role::Accountant);
            $booked = $this->book();
            $invoice = $this->issuedInvoiceFor($booked->appointment);
            $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
                method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'iso-refund',
            ), $this->staffActor());
            app(IssueRefund::class)->handle($paid->payment, new RefundRequest(reasonCode: RefundReason::Goodwill), $this->staffActor());
        });
    }
}
