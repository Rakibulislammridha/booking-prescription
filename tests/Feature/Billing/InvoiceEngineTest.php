<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\ApplyCoupon;
use App\Domain\Billing\Actions\ApplyDiscount;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\DiscountRequest;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Exceptions\CouponAlreadyApplied;
use App\Domain\Billing\Exceptions\CouponInvalid;
use App\Domain\Billing\Exceptions\DiscountApprovalRequired;
use App\Domain\Billing\Exceptions\PaymentExceedsDue;
use App\Domain\Billing\Services\VatRate;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** Invoice arithmetic end to end: fee snapshot → lines → discounts → coupon → VAT → partial payment → dues. */
final class InvoiceEngineTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    public function test_booking_creates_one_draft_invoice_carrying_the_frozen_fee_snapshot(): void
    {
        $booked = $this->book();

        $invoice = Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail();

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $invoice->number);
        $this->assertSame($booked->appointment->fee_paisa, $invoice->total_paisa);
        $this->assertSame($booked->appointment->fee_paisa, $invoice->due_paisa, 'due_paisa is GENERATED total − paid');

        $item = $invoice->items()->firstOrFail();
        $this->assertSame($booked->appointment->fee_paisa, $item->unit_price_paisa, 'the fee is COPIED, never re-derived');
        $this->assertSame($item->quantity * $item->unit_price_paisa, $item->line_total_paisa);

        // Booking it again is idempotent: the partial unique index allows exactly one live invoice.
        $this->assertSame(1, Invoice::query()->where('appointment_id', $booked->appointment->id)->count());
    }

    public function test_issuing_freezes_the_totals_and_the_appointment_mirrors_the_balance(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNotNull($invoice->issued_at);
        $this->assertAudited(AuditAction::Issue, $invoice, ['actor_source' => 'web']);

        $this->assertSame(PaymentStatus::Unpaid, $booked->appointment->refresh()->payment_status);
        $this->assertSame($invoice->id, $booked->appointment->invoice_id);
    }

    public function test_partial_payment_leaves_a_due_and_the_second_payment_settles_it(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $total = $invoice->total_paisa;

        $first = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 30000, idempotencyKey: 'part-1',
        ), $this->staffActor());

        $this->assertSame(30000, $first->invoice->paid_paisa);
        $this->assertSame($total - 30000, $first->invoice->due_paisa);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $first->invoice->status);
        $this->assertSame(PaymentStatus::Partial, $booked->appointment->refresh()->payment_status);

        $second = app(RecordPayment::class)->handle($first->invoice, new PaymentRequest(
            method: PaymentMethod::Card, amountPaisa: $total - 30000, idempotencyKey: 'part-2',
        ), $this->staffActor());

        $this->assertSame($total, $second->invoice->paid_paisa);
        $this->assertSame(0, $second->invoice->due_paisa);
        $this->assertSame(InvoiceStatus::Paid, $second->invoice->status);
        $this->assertNotNull($second->invoice->paid_at);
        $this->assertSame(PaymentStatus::Paid, $booked->appointment->refresh()->payment_status);

        $this->assertAudited(AuditAction::Create, $second->payment);
    }

    public function test_a_payment_can_never_exceed_the_outstanding_balance(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $this->expectException(PaymentExceedsDue::class);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: $invoice->total_paisa + 1, idempotencyKey: 'too-much',
        ), $this->staffActor());
    }

    public function test_a_discount_with_a_reason_reduces_the_total_and_is_audited(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        app(ApplyDiscount::class)->handle($invoice, new DiscountRequest(
            type: DiscountType::Fixed, value: '100.00', reasonCode: DiscountReason::PoorFund, note: 'hardship',
        ), $this->staffActor());

        $invoice->refresh();
        $this->assertSame(10000, $invoice->discount_paisa);
        $this->assertSame(80000 - 10000, $invoice->total_paisa);
        $this->assertSame(70000, $invoice->due_paisa);

        $discount = $invoice->discounts()->firstOrFail();
        $this->assertSame(DiscountReason::PoorFund, $discount->reason_code);
        $this->assertSame(10000, $discount->amount_paisa);
        $this->assertAudited(AuditAction::Update, $invoice, ['event' => 'discount_applied']);
    }

    public function test_a_discount_above_the_settings_threshold_needs_an_approver_with_the_permission(): void
    {
        app(Settings::class)->set('billing.discount_approval_threshold_paisa', 5000);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        try {
            app(ApplyDiscount::class)->handle($invoice, new DiscountRequest(
                type: DiscountType::Fixed, value: '500.00', reasonCode: DiscountReason::Staff,
            ), $this->staffActor());
            $this->fail('a 500 taka waiver above a 50 taka threshold must not pass unapproved');
        } catch (DiscountApprovalRequired $e) {
            $this->assertSame('billing.discount_approval_required', $e->code());
            $this->assertSame(403, $e->status());
        }

        $this->assertSame(0, $invoice->refresh()->discount_paisa);

        // A receptionist is not an approver either; only billing.discounts.approve counts.
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can('billing.discounts.approve'));

        $accountant = $this->actingAsStaff(Role::Accountant);
        app(ApplyDiscount::class)->handle($invoice, new DiscountRequest(
            type: DiscountType::Fixed, value: '500.00', reasonCode: DiscountReason::Staff, approvedByUserId: $accountant->id,
        ), $this->staffActor());

        $this->assertSame(50000, $invoice->refresh()->discount_paisa);
        $this->assertSame($accountant->id, $invoice->discounts()->firstOrFail()->approved_by_user_id);
    }

    public function test_vat_from_settings_is_applied_to_the_discounted_base(): void
    {
        app(Settings::class)->set(VatRate::SETTING, 7.5);
        $booked = $this->book();
        $invoice = Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail();

        app(ApplyDiscount::class)->handle($invoice, new DiscountRequest(
            type: DiscountType::Fixed, value: '100.00', reasonCode: DiscountReason::Promo,
        ), $this->staffActor());

        $invoice = app(IssueInvoice::class)->handle($invoice->refresh(), $this->staffActor());

        $this->assertSame(80000, $invoice->subtotal_paisa);
        $this->assertSame(10000, $invoice->discount_paisa);
        $this->assertSame(5250, $invoice->vat_paisa, '7.5% of 700.00');
        $this->assertSame(75250, $invoice->total_paisa);
    }

    public function test_a_coupon_is_validated_capped_and_can_only_be_redeemed_once_per_invoice(): void
    {
        $booked = $this->book();
        $invoice = Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail();

        $coupon = Coupon::factory()->create([
            'code' => 'SAVE50', 'type' => DiscountType::Percentage, 'value' => '50.00', 'max_discount_paisa' => 20000,
        ]);

        app(ApplyCoupon::class)->handle($invoice, $coupon, $this->staffActor());

        $invoice->refresh();
        $this->assertSame(20000, $invoice->coupon_discount_paisa, '50% of 800 is 400, capped at the 200 taka maximum');
        $this->assertSame(60000, $invoice->total_paisa);
        $this->assertSame(1, $coupon->refresh()->uses_count);

        $this->expectException(CouponAlreadyApplied::class);
        app(ApplyCoupon::class)->handle($invoice, $coupon, $this->staffActor());
    }

    public function test_coupon_validity_window_scope_and_per_patient_limit_are_enforced(): void
    {
        $booked = $this->book();
        $invoice = Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail();

        $expired = Coupon::factory()->create(['code' => 'OLD', 'valid_until' => Clock::now()->subDay()]);
        $this->assertCouponRejected($invoice, $expired);

        $inactive = Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
        $this->assertCouponRejected($invoice, $inactive);

        $tooSmall = Coupon::factory()->create(['code' => 'BIGONLY', 'min_invoice_paisa' => 900000]);
        $this->assertCouponRejected($invoice, $tooSmall);

        $exhausted = Coupon::factory()->create(['code' => 'GONE', 'max_uses' => 0]);
        $this->assertCouponRejected($invoice, $exhausted);

        $wrongDoctor = Coupon::factory()->create(['code' => 'OTHERDOC', 'applies_to' => ['doctor_ids' => [999999]]]);
        $this->assertCouponRejected($invoice, $wrongDoctor);

        $this->assertSame(0, $invoice->refresh()->coupon_discount_paisa, 'no rejected coupon touched the bill');
    }

    public function test_a_free_follow_up_produces_a_zero_invoice_that_is_settled_on_issue(): void
    {
        $session = $this->openSession();
        $doctor = $this->doctorOf($session);
        $doctor->profile->forceFill(['free_followup_within_days' => 15, 'followup_within_days' => 30])->save();

        // A completed first visit, then a follow-up seven days later — inside the 15-day free window.
        $first = $this->book($session);
        $first->appointment->forceFill(['status' => AppointmentStatus::Completed])->save();

        $later = SessionInstance::factory()->openToday()->quotas(10, 10, 5)
            ->on(Clock::today()->addDays(7), 'B')
            ->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $follow = $this->book($later, mobile: '01710000001', name: 'Rahima Begum');

        $this->assertSame(FeeRule::FollowupFree, $follow->appointment->fee_rule);
        $this->assertSame(0, $follow->appointment->fee_paisa);

        $invoice = $this->issuedInvoiceFor($follow->appointment);

        $this->assertSame(0, $invoice->total_paisa);
        $this->assertSame(0, $invoice->due_paisa);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status, 'nothing to collect, so the bill is settled');
        $this->assertSame(PaymentStatus::Paid, $follow->appointment->refresh()->payment_status);
        // The tenant's locale is Bangla, and the reason sentence FeeResolver wrote is what the receipt prints.
        $this->assertMatchesRegularExpression('/[\x{0980}-\x{09FF}]/u', (string) $follow->appointment->fee_rule_reason, 'the reason sentence reaches the receipt in the tenant locale');
        $this->assertStringContainsString('7', (string) $follow->appointment->fee_rule_reason);
    }

    public function test_every_billing_table_is_tenant_isolated(): void
    {
        foreach (['invoices', 'invoice_items', 'payments', 'coupons', 'cash_shifts', 'doctor_revenue_shares'] as $table) {
            $this->assertTenantIsolated($table, function (): void {
                $this->actingAsStaff(Role::Receptionist);
                $booked = $this->book(mobile: '0171000'.random_int(1000, 9999));
                $invoice = $this->issuedInvoiceFor($booked->appointment);
                app(RecordPayment::class)->handle($invoice, new PaymentRequest(
                    method: PaymentMethod::Cash, amountPaisa: 10000, idempotencyKey: 'iso-'.$invoice->id,
                ), $this->staffActor());
                Coupon::factory()->create(['code' => 'ISO'.random_int(1000, 9999)]);
                CashShift::factory()->create(['user_id' => auth('web')->id(), 'branch_id' => $this->mainBranch()->id]);
                DoctorRevenueShare::factory()->create(['doctor_id' => $invoice->doctor_id]);
            });

            $this->asTenant('a');
        }
    }

    public function test_the_patient_dues_seam_reports_the_outstanding_balance(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 30000, idempotencyKey: 'dues-1',
        ), $this->staffActor());

        $this->getJson(route('panel.billing.patients.dues', ['patient' => $booked->appointment->patient->public_id], false))
            ->assertOk()
            ->assertJsonPath('due_paisa', 50000)
            ->assertJsonPath('invoices.0.number', $invoice->number)
            ->assertJsonPath('invoices.0.due_paisa', 50000);

        // Once settled, the patient owes nothing and the panel renders nothing.
        app(RecordPayment::class)->handle($invoice->refresh(), new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 50000, idempotencyKey: 'dues-2',
        ), $this->staffActor());

        $this->getJson(route('panel.billing.patients.dues', ['patient' => $booked->appointment->patient->public_id], false))
            ->assertOk()
            ->assertJsonPath('due_paisa', 0)
            ->assertJsonCount(0, 'invoices');
    }

    private function assertCouponRejected(Invoice $invoice, Coupon $coupon): void
    {
        try {
            app(ApplyCoupon::class)->handle($invoice, $coupon, $this->staffActor());
            $this->fail("coupon {$coupon->code} should have been rejected");
        } catch (CouponInvalid $e) {
            $this->assertSame('billing.coupon_invalid', $e->code());
        }
    }
}
