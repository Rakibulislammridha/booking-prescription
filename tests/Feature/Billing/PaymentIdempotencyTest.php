<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\AddInvoiceItem;
use App\Domain\Billing\Actions\CreateInvoiceForAppointment;
use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Services\CashCollectorService;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Support\Str;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * The single most important property of this module: a retry — of a request, of an offline event, of a webhook —
 * must never create a second charge.
 */
final class PaymentIdempotencyTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    public function test_the_same_idempotency_key_records_one_payment_however_often_it_is_sent(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $results = [];

        for ($i = 0; $i < 5; $i++) {
            $results[] = app(RecordPayment::class)->handle($invoice->refresh(), new PaymentRequest(
                method: PaymentMethod::Cash, amountPaisa: 40000, idempotencyKey: 'desk-retry-1',
            ), $this->staffActor());
        }

        $this->assertFalse($results[0]->duplicate);
        foreach (array_slice($results, 1) as $replay) {
            $this->assertTrue($replay->duplicate, 'every retry after the first must be a no-op');
            $this->assertSame($results[0]->payment->id, $replay->payment->id);
        }

        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(40000, $invoice->refresh()->paid_paisa, 'the balance was recomputed, not incremented');
    }

    public function test_a_replayed_offline_cash_event_does_not_double_charge(): void
    {
        $booked = $this->book();
        $collector = app(CashCollector::class);
        $this->assertInstanceOf(CashCollectorService::class, $collector, 'Billing must own the CashCollector seam');

        $receipt = 'D2-000123';
        $actor = new Actor(userId: (int) auth('web')->id(), source: 'offline_replay');

        $first = $collector->collect($booked->appointment, 80000, $receipt, $actor);
        $this->assertFalse($first->duplicate);
        $this->assertSame($receipt, $first->receiptNo, 'the device-printed slip number is kept, not renumbered');

        // The device reconnects twice more and replays the same event.
        $second = $collector->collect($booked->appointment->refresh(), 80000, $receipt, $actor);
        $third = $collector->collect($booked->appointment->refresh(), 80000, $receipt, $actor);

        $this->assertTrue($second->duplicate);
        $this->assertTrue($third->duplicate);
        $this->assertTrue($collector->wasRecorded($booked->appointment, $receipt));

        $payments = Payment::query()->where('receipt_number', $receipt)->get();
        $this->assertCount(1, $payments, 'exactly one payment row exists for the slip');
        $this->assertSame(80000, (int) $payments->sum('amount_paisa'));

        $invoice = $this->issuedInvoiceFor($booked->appointment->refresh());
        $this->assertSame(80000, $invoice->paid_paisa);
        $this->assertSame(0, $invoice->due_paisa);
    }

    public function test_the_receipt_number_alone_is_enough_to_stop_a_double_post(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 20000, idempotencyKey: 'key-a', receiptNumber: 'RCT-DUP-1',
        ), $this->staffActor());

        // A different idempotency key but the same printed receipt: still one charge.
        $again = app(RecordPayment::class)->handle($invoice->refresh(), new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 20000, idempotencyKey: 'key-b', receiptNumber: 'RCT-DUP-1',
        ), $this->staffActor());

        $this->assertTrue($again->duplicate);
        $this->assertSame(1, Payment::query()->where('receipt_number', 'RCT-DUP-1')->count());
        $this->assertSame(20000, $invoice->refresh()->paid_paisa);
    }

    public function test_the_desk_endpoint_honours_a_client_event_id_on_a_double_submit(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $clientEventId = (string) Str::ulid();

        $payload = ['amount_paisa' => 25000, 'method' => 'cash', 'client_event_id' => $clientEventId];
        $url = route('panel.billing.invoices.payments.store', ['invoice' => $invoice->public_id], false);

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('payment.duplicate', false);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('payment.duplicate', true);

        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(25000, $invoice->refresh()->paid_paisa);
    }

    public function test_a_zero_due_invoice_takes_no_money_at_all(): void
    {
        $booked = $this->book();

        // The fee was waived before anything was issued: SCHEMA §5.10's "the draft invoice line is replaced".
        $booked->appointment->forceFill(['fee_paisa' => 0, 'fee_rule' => FeeRule::Waived])->save();
        $invoice = Invoice::query()->where('appointment_id', $booked->appointment->id)->firstOrFail();
        app(AddInvoiceItem::class)->replaceConsultationLine(
            $invoice,
            app(CreateInvoiceForAppointment::class)->consultationLine($booked->appointment->refresh()),
        );

        $result = app(RecordCashPayment::class)->handle($booked->appointment->refresh(), 0, 'C-FREE-1', $this->staffActor());

        $this->assertSame(0, $result->invoice->total_paisa);
        $this->assertSame(0, $result->payment->amount_paisa);
        $this->assertFalse($result->payment->exists, 'no payments row: amount_paisa > 0 is a CHECK and zero is not money');
        $this->assertSame(0, Payment::query()->where('invoice_id', $result->invoice->id)->count());
    }
}
