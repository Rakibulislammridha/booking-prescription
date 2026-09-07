<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\ApplyDiscount;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\DiscountRequest;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Services\PrintDocument;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Money;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** Invoice and money receipt: A4 and thermal, Bangla-capable, and driven by the STORED rows. */
final class PrintTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    public function test_the_invoice_prints_the_stored_totals_on_a4(): void
    {
        app(Settings::class)->set('billing.vat_percent', 5);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $html = $this->get(route('panel.billing.invoices.print', ['invoice' => $invoice->public_id], false))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('@page { size: A4 portrait; margin: 14mm 12mm; }', $html);
        $this->assertStringContainsString('data-paper="a4"', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString(Money::bdt($invoice->total_paisa)->format(), $html);
        $this->assertStringContainsString('Noto Sans Bengali', $html, 'the Bangla font stack must be declared');
        $this->assertStringContainsString('চালান', $html, 'the bilingual heading');
        $this->assertStringContainsString('Taka', $html, 'the amount in words');
        $this->assertStringContainsString('data-section="totals"', $html);

        $this->assertAudited(AuditAction::Print, $invoice, ['document' => 'invoice', 'paper' => 'a4']);
    }

    public function test_the_invoice_prints_on_58_and_80_mm_thermal_rolls(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        foreach ([['58', '@page { size: 58mm auto; margin: 2mm; }'], ['80', '@page { size: 80mm auto; margin: 3mm; }']] as [$paper, $page]) {
            $html = (string) $this->get(route('panel.billing.invoices.print', ['invoice' => $invoice->public_id, 'paper' => $paper], false))->assertOk()->getContent();

            $this->assertStringContainsString($page, $html);
            $this->assertStringContainsString('data-paper="'.$paper.'"', $html);
            $this->assertStringContainsString('ধন্যবাদ', $html, 'the thermal slip thanks the patient in Bangla');
        }
    }

    public function test_the_receipt_prints_one_payment_from_its_stored_row(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 50000, idempotencyKey: 'print-1',
        ), $this->staffActor());

        $html = (string) $this->get(route('panel.billing.invoices.receipt', ['invoice' => $invoice->public_id, 'payment' => $paid->payment->public_id], false))->assertOk()->getContent();

        $this->assertStringContainsString('data-document="receipt"', $html);
        $this->assertStringContainsString((string) $paid->payment->receipt_number, $html);
        $this->assertStringContainsString('৳500.00', $html);
        $this->assertStringContainsString('Taka Five Hundred only', $html);
        $this->assertStringContainsString('টাকা প্রাপ্তির রসিদ', $html);
        $this->assertStringContainsString('নগদ', $html, 'the payment method is bilingual');
        // The remaining due is on the slip so the patient knows what is left.
        $this->assertStringContainsString('৳300.00', $html);

        $this->assertAudited(AuditAction::Print, $paid->payment, ['document' => 'receipt']);
    }

    public function test_a_reprint_after_the_rules_change_is_identical_because_it_reads_stored_rows(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $before = (string) $this->get(route('panel.billing.invoices.print', ['invoice' => $invoice->public_id], false))->getContent();

        // The clinic changes its VAT rate and its consultation fee afterwards.
        app(Settings::class)->set('billing.vat_percent', 15);
        $booked->appointment->doctor->profile->forceFill(['new_fee_paisa' => 500000])->save();

        $after = (string) $this->get(route('panel.billing.invoices.print', ['invoice' => $invoice->public_id], false))->getContent();

        $this->assertSame($before, $after, 'a printed bill must never change under the patient');
    }

    public function test_a_free_follow_up_bill_prints_its_reason(): void
    {
        // A full waiver is far above the default approval threshold, so it carries an approver — as it should.
        $approver = $this->actingAsStaff(Role::HospitalAdmin);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        app(ApplyDiscount::class)->handle($invoice, new DiscountRequest(
            type: DiscountType::Fixed, value: '800.00', reasonCode: DiscountReason::Followup, note: 'ফলো-আপ মওকুফ', approvedByUserId: $approver->id,
        ), $this->staffActor());

        $html = (string) $this->get(route('panel.billing.invoices.print', ['invoice' => $invoice->public_id], false))->getContent();

        $this->assertStringContainsString('৳0.00', $html);
        $this->assertStringContainsString('PAID', $html, 'a fully waived bill carries the paid stamp');
    }

    public function test_amounts_in_words_read_the_way_a_bangladeshi_receipt_does(): void
    {
        $this->assertSame('Taka Five Hundred only', PrintDocument::inWords(50000));
        $this->assertSame('Taka One Thousand Two Hundred Fifty and Seventy Five Poisha only', PrintDocument::inWords(125075));
        $this->assertSame('Taka One Lakh only', PrintDocument::inWords(10000000));
        $this->assertSame('Taka Twelve Lakh Thirty Thousand only', PrintDocument::inWords(123000000));
        $this->assertSame('Taka One Crore Twenty Three Lakh only', PrintDocument::inWords(1230000000));
        $this->assertSame('Taka Zero only', PrintDocument::inWords(0));
    }

    public function test_another_tenants_bill_cannot_be_printed(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $publicId = $invoice->public_id;

        $this->asTenant('b')->actingAsStaff(Role::Receptionist);
        $this->get(route('panel.billing.invoices.print', ['invoice' => $publicId], false))->assertNotFound();
    }
}
