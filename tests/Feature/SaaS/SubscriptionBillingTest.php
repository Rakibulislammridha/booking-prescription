<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\RecordSubscriptionPayment;
use App\Domain\SaaS\Actions\Billing\SettleSubscriptionGatewayPayment;
use App\Domain\SaaS\Actions\Billing\StartSubscriptionCheckout;
use App\Domain\SaaS\Data\RecordPaymentData;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Exceptions\InvoiceNotPayable;
use App\Domain\SaaS\Gateways\LogSubscriptionGateway;
use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Money. Integer paisa end to end, a replayed gateway callback settling exactly once, and a payment that clears
 * the arrears bringing the clinic back by itself.
 */
final class SubscriptionBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.gateways.driver' => 'log']);       // the platform's reference driver, like Billing's tests
    }

    public function test_an_invoice_is_generated_and_issued_in_integer_paisa_with_a_sequential_number(): void
    {
        [$tenant, $subscription] = $this->paidTenant(400000);

        $invoice = app(GenerateSubscriptionInvoice::class)->handle($subscription, CarbonImmutable::now(), CarbonImmutable::now()->addMonth());

        $this->assertSame(SubscriptionInvoiceStatus::Draft, $invoice->status);
        $this->assertSame(400000, (int) $invoice->getAttribute('subtotal_paisa'));
        $this->assertSame(400000, $invoice->total_paisa);
        $this->assertSame(0, (int) $invoice->getAttribute('tax_paisa'));
        $this->assertMatchesRegularExpression('/^SI-\d{4}-\d{6}$/', $invoice->number);
        $this->assertIsInt($invoice->total_paisa);

        app(IssueSubscriptionInvoice::class)->handle($invoice);
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->refresh()->status);

        // A second invoice for the SAME period is the same row: a retried sweep never bills twice.
        $again = app(GenerateSubscriptionInvoice::class)->handle($subscription, $invoice->getAttribute('period_start'), $invoice->getAttribute('period_end'));
        $this->assertSame($invoice->id, $again->id);
    }

    public function test_a_zero_total_invoice_is_issued_and_settled_in_one_step(): void
    {
        [$tenant, $subscription] = $this->paidTenant(0);
        $invoice = app(IssueSubscriptionInvoice::class)->handle(
            app(GenerateSubscriptionInvoice::class)->handle($subscription, CarbonImmutable::now(), CarbonImmutable::now()->addMonth()),
        );

        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->status, 'nothing to collect must not enter the dunning ladder');
        $this->assertNotNull($invoice->getAttribute('paid_at'));
    }

    public function test_a_partial_payment_leaves_the_invoice_open_and_the_second_one_settles_it(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);

        app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(150000, 'REF-1'));
        $this->assertSame(150000, (int) $invoice->refresh()->getAttribute('paid_paisa'));
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->status);

        app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(250000, 'REF-2'));
        $this->assertSame(400000, (int) $invoice->refresh()->getAttribute('paid_paisa'));
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->status);

        $this->assertThrows(fn () => app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(1, 'REF-3')), InvoiceNotPayable::class);
    }

    public function test_an_overpayment_is_refused_rather_than_clamped(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);

        $this->assertThrows(fn () => app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(400001, 'REF-BIG')), InvoiceNotPayable::class);
        $this->assertSame(0, (int) $invoice->refresh()->getAttribute('paid_paisa'));
    }

    public function test_the_same_manual_reference_applied_twice_settles_the_invoice_once(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);

        $first = app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(400000, 'BANK-991'));
        $second = app(RecordSubscriptionPayment::class)->handle($invoice->refresh(), $this->payment(400000, 'BANK-991'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->count());
        $this->assertSame(400000, (int) $invoice->refresh()->getAttribute('paid_paisa'));
    }

    /** The one the brief names explicitly: a gateway that delivers the same callback five times. */
    public function test_a_replayed_gateway_callback_settles_the_invoice_exactly_once(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);

        $session = app(StartSubscriptionCheckout::class)->handle(
            $invoice,
            PaymentGateway::Sslcommerz,
            callbackUrl: 'http://bp.test/billing/callback/sslcommerz',
            cancelUrl: 'http://bp.test/billing/invoice/'.$invoice->public_id,
        );

        $pending = SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(SubscriptionPaymentStatus::Pending, $pending->status);
        $this->assertSame(400000, $pending->amount_paisa, 'the amount is frozen at checkout, not taken from the callback');

        $request = $this->callbackRequest($session->redirectUrl);
        $driver = app(SubscriptionGatewayManager::class)->driver(PaymentGateway::Sslcommerz);
        $callback = $driver->verifyCallback($request);

        $settle = app(SettleSubscriptionGatewayPayment::class);
        $payments = [];

        for ($i = 0; $i < 5; $i++) {
            $payments[] = $settle->handle($callback)?->id;
        }

        $this->assertSame([$pending->id, $pending->id, $pending->id, $pending->id, $pending->id], $payments);
        $this->assertSame(1, SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->count());
        $this->assertSame(SubscriptionPaymentStatus::Succeeded, $pending->refresh()->status);
        $this->assertSame(400000, (int) $invoice->refresh()->getAttribute('paid_paisa'));
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->status);
    }

    public function test_a_tampered_callback_signature_is_refused_and_nothing_is_credited(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);
        $session = app(StartSubscriptionCheckout::class)->handle($invoice, PaymentGateway::Sslcommerz, 'http://bp.test/cb', 'http://bp.test/x');

        $tampered = $this->callbackRequest(str_replace('status=success', 'status=success&amount=1', $session->redirectUrl));
        $tampered->merge(['sig' => 'not-the-signature']);

        $this->assertThrows(
            fn () => app(SubscriptionGatewayManager::class)->driver(PaymentGateway::Sslcommerz)->verifyCallback($tampered),
            GatewaySignatureInvalid::class,
        );
        $this->assertSame(0, (int) $invoice->refresh()->getAttribute('paid_paisa'));
    }

    public function test_a_callback_that_reports_failure_marks_the_payment_failed_and_credits_nothing(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000);
        app(StartSubscriptionCheckout::class)->handle($invoice, PaymentGateway::Sslcommerz, 'http://bp.test/cb', 'http://bp.test/x');
        $pending = SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->firstOrFail();

        $ref = $pending->public_id;
        $txn = 'PLTFAIL';
        $url = 'http://bp.test/cb?'.http_build_query(['ref' => $ref, 'txn' => $txn, 'status' => 'cancel', 'sig' => LogSubscriptionGateway::sign($ref, $txn, 'cancel')]);
        $callback = app(SubscriptionGatewayManager::class)->driver(PaymentGateway::Sslcommerz)->verifyCallback($this->callbackRequest($url));

        app(SettleSubscriptionGatewayPayment::class)->handle($callback);

        $this->assertSame(SubscriptionPaymentStatus::Failed, $pending->refresh()->status);
        $this->assertSame(0, (int) $invoice->refresh()->getAttribute('paid_paisa'));
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->status);
    }

    public function test_paying_the_arrears_reactivates_a_suspended_clinic_and_a_partial_payment_does_not(): void
    {
        [$tenant, $invoice] = $this->issuedInvoice(400000, dueAt: CarbonImmutable::now()->subDays(20));
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();
        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => CarbonImmutable::now()])->save();

        app(RecordSubscriptionPayment::class)->handle($invoice, $this->payment(100000, 'PART'));
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status, 'part of the arrears is not the arrears');

        app(RecordSubscriptionPayment::class)->handle($invoice->refresh(), $this->payment(300000, 'REST'));

        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->assertNull($tenant->suspended_at);
        $this->assertNull($tenant->suspension_reason);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertTrue($subscription->current_period_end->isFuture(), 'a revived subscription needs a live period or it renews into the past');
    }

    private function payment(int $paisa, string $reference): RecordPaymentData
    {
        return new RecordPaymentData(
            amountPaisa: $paisa,
            method: SubscriptionPaymentMethod::BankTransfer,
            gatewayTxnId: $reference,
            idempotencyKey: 'manual:'.$reference,
        );
    }

    private function callbackRequest(string $url): Request
    {
        return Request::create($url, 'GET');
    }

    /** @return array{0: Tenant, 1: Subscription} */
    private function paidTenant(int $priceMonthly): array
    {
        $tenant = $this->tenant('a');
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'price_paisa' => $priceMonthly,
            'current_period_start' => CarbonImmutable::now(),
            'current_period_end' => CarbonImmutable::now()->addMonth(),
        ])->save();
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        return [$tenant, $subscription];
    }

    /** @return array{0: Tenant, 1: SubscriptionInvoice} */
    private function issuedInvoice(int $paisa, ?CarbonImmutable $dueAt = null): array
    {
        [$tenant, $subscription] = $this->paidTenant($paisa);
        $invoice = app(GenerateSubscriptionInvoice::class)->handle($subscription, CarbonImmutable::now(), CarbonImmutable::now()->addMonth());
        app(IssueSubscriptionInvoice::class)->handle($invoice, $dueAt);

        return [$tenant, $invoice->refresh()];
    }
}
