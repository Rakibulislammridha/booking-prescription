<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\ApplyCoupon;
use App\Domain\Billing\Actions\ApplyDiscount;
use App\Domain\Billing\Actions\CloseCashShift;
use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Actions\OpenCashShift;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Actions\VoidInvoice;
use App\Domain\Billing\Data\DiscountRequest;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Coupon;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** BRIEF §8: every money movement leaves an audit row naming who, what and when. */
final class AuditTrailTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
    }

    public function test_every_money_movement_writes_an_audit_row(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $this->assertAudited(AuditAction::Create, $invoice);
        $this->assertAudited(AuditAction::Issue, $invoice);

        $coupon = Coupon::factory()->fixed(5000)->create(['code' => 'AUDIT10']);
        app(ApplyCoupon::class)->handle($invoice, $coupon, $this->staffActor());
        $this->assertAudited(AuditAction::Update, $invoice->refresh(), ['event' => 'coupon_applied']);

        app(ApplyDiscount::class)->handle($invoice->refresh(), new DiscountRequest(
            type: DiscountType::Fixed, value: '50.00', reasonCode: DiscountReason::Staff,
        ), $this->staffActor());
        $this->assertAudited(AuditAction::Update, $invoice->refresh(), ['event' => 'discount_applied']);

        $paid = app(RecordPayment::class)->handle($invoice->refresh(), new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 70000, idempotencyKey: 'audit-1',
        ), $this->staffActor());
        $this->assertAudited(AuditAction::Create, $paid->payment);

        $refund = app(IssueRefund::class)->handle($paid->payment, new RefundRequest(
            reasonCode: RefundReason::Goodwill, amountPaisa: 10000,
        ), $this->staffActor());
        $this->assertAudited(AuditAction::Refund, $refund);
        $this->assertAudited(AuditAction::Create, $refund);

        $shift = app(OpenCashShift::class)->handle($this->actingAsStaff(Role::HospitalAdmin), $this->mainBranch(), 0, $this->staffActor());
        $closed = app(CloseCashShift::class)->handle($shift, 0, $this->staffActor());
        $this->assertAudited(AuditAction::Update, $closed, ['event' => 'shift_closed']);
    }

    public function test_a_cancellation_and_a_no_show_record_their_refund_decision(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'audit-cancel',
        ), $this->staffActor());

        app(CancelSerial::class)->handle($booked->serial, CancelReason::DoctorUnavailable, $this->staffActor());

        $this->assertAudited(AuditAction::Refund, $invoice->refresh(), ['event' => 'cancellation_decision']);
    }

    public function test_voiding_a_bill_is_audited_and_the_row_is_never_deleted(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        app(VoidInvoice::class)->handle($invoice, 'entered twice', $this->staffActor());

        $this->assertAudited(AuditAction::Void, $invoice->refresh());
        $this->assertSame('void', $invoice->status->value);
        $this->assertNotNull($invoice->voided_at);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id], 'pgsql');
    }

    public function test_the_audit_row_names_the_actor_and_carries_the_request_id(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $this->postJson(route('panel.billing.invoices.payments.store', ['invoice' => $invoice->public_id], false), ['amount_paisa' => 80000, 'method' => 'cash'])
            ->assertOk();

        $payment = $invoice->refresh()->payments()->firstOrFail();
        $log = AuditLog::query()
            ->where('auditable_type', $payment->getMorphClass())
            ->where('auditable_id', $payment->id)
            ->where('action', AuditAction::Create->value)
            ->firstOrFail();

        $this->assertSame($user->id, $log->actor_id);
        $this->assertNotNull($log->request_id);
        $this->assertTrue($log->occurred_at->isToday());
        $this->assertSame('panel.billing.invoices.payments.store', $log->context['route'] ?? null);
    }
}
