<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\SettleGatewayPayment;
use App\Domain\Billing\Actions\StartOnlinePayment;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Exceptions\GatewayAmountMismatch;
use App\Domain\Billing\Exceptions\GatewayNotConfigured;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\Billing\Gateways\BkashGateway;
use App\Domain\Billing\Gateways\GatewayConfig;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Billing\Gateways\LogPaymentGateway;
use App\Domain\Billing\Gateways\NagadGateway;
use App\Domain\Billing\Gateways\SslcommerzGateway;
use App\Domain\Billing\Services\BillingOnlinePaymentGateway;
use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Services\NoOnlinePayment;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Models\Tenant\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * Each driver's callback verified, replayed and tampered with. The invariant under test is always the same: a
 * callback carries identifiers only, the amount comes from the gateway server-to-server, and a second delivery
 * changes nothing.
 */
final class GatewayTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    public function test_online_payment_is_off_until_a_gateway_is_configured(): void
    {
        $seam = app(OnlinePaymentGateway::class);
        $this->assertInstanceOf(BillingOnlinePaymentGateway::class, $seam, 'Billing must own the OnlinePaymentGateway seam');
        $this->assertFalse($seam->enabled(), 'no credentials configured, so the site keeps saying "pay at counter"');
        $this->assertSame([], app(GatewayManager::class)->available());

        $booked = $this->book();
        $this->assertNull($seam->checkoutUrl($booked->appointment));

        $this->expectException(GatewayNotConfigured::class);
        app(GatewayManager::class)->configuredDriver(PaymentGateway::Bkash);
    }

    public function test_a_configured_gateway_turns_the_seam_on_once_the_tenant_switch_is_on(): void
    {
        config(['billing.gateways.sslcommerz' => ['store_id' => 'test', 'store_password' => 'secret']]);
        app(GatewayManager::class)->forget();

        $this->assertTrue(app(GatewayManager::class)->driver(PaymentGateway::Sslcommerz)->isConfigured());
        $this->assertFalse(app(GatewayManager::class)->driver(PaymentGateway::Bkash)->isConfigured());
        $this->assertSame([PaymentGateway::Sslcommerz], app(GatewayManager::class)->available());

        // Credentials alone do not open online payment to patients: `booking.online_payment_enabled`
        // (SCHEMA Appendix B) defaults to false, so a merchant account can be configured and tested first.
        $this->assertFalse(app(OnlinePaymentGateway::class)->enabled(), 'credentials without the tenant switch');

        app(Settings::class)->set(NoOnlinePayment::SETTING, true);
        $this->assertTrue(app(OnlinePaymentGateway::class)->enabled());
    }

    public function test_the_pending_payment_freezes_our_amount_before_the_gateway_is_told_anything(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $result = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');

        $payment = $result['payment'];
        $this->assertSame(PaymentTxnStatus::Pending, $payment->status);
        $this->assertSame($invoice->total_paisa, $payment->amount_paisa, 'the amount is ours, decided before the redirect');
        $this->assertNotNull($payment->gateway_payment_ref);
        $this->assertSame(0, $invoice->refresh()->paid_paisa, 'a pending payment is not money');

        // Tapping "pay" again reuses the same attempt rather than starting a second charge.
        $again = app(StartOnlinePayment::class)->handle($invoice->refresh(), PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');
        $this->assertSame($payment->id, $again['payment']->id);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_verified_callback_settles_once_and_a_replay_is_a_no_op(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $started = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');

        $driver = app(GatewayManager::class)->driver(PaymentGateway::Sslcommerz);
        $ref = (string) $started['payment']->gateway_payment_ref;
        $txn = 'TXN-1';
        $query = ['ref' => $ref, 'txn' => $txn, 'status' => 'success', 'sig' => LogPaymentGateway::sign($ref, $txn, 'success')];

        $first = app(SettleGatewayPayment::class)->handle($driver, $driver->verifyCallback(Request::create('/cb', 'GET', $query)));

        $this->assertFalse($first->duplicate);
        $this->assertSame(PaymentTxnStatus::Succeeded, $first->payment->status);
        $this->assertNotNull($first->payment->receipt_number);
        $this->assertSame($invoice->total_paisa, $first->invoice->paid_paisa);
        $this->assertSame(0, $first->invoice->due_paisa);

        // The gateway re-delivers the webhook (they all do). Nothing moves.
        $second = app(SettleGatewayPayment::class)->handle($driver, $driver->verifyCallback(Request::create('/cb', 'GET', $query)));

        $this->assertTrue($second->duplicate);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame($invoice->total_paisa, $invoice->refresh()->paid_paisa);
    }

    public function test_a_tampered_signature_is_rejected_and_nothing_is_credited(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $started = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');
        $ref = (string) $started['payment']->gateway_payment_ref;

        $this->postJson('/api/webhooks/payments/sslcommerz', ['ref' => $ref, 'txn' => 'FAKE', 'status' => 'success', 'sig' => 'not-the-signature'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        // An unknown reference with a *valid-looking* signature matches no pending payment of this tenant.
        $this->postJson('/api/webhooks/payments/sslcommerz', ['ref' => 'SOMEONE-ELSE', 'txn' => 'X', 'status' => 'success', 'sig' => LogPaymentGateway::sign('SOMEONE-ELSE', 'X', 'success')])
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(PaymentTxnStatus::Pending, $started['payment']->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->paid_paisa);
    }

    public function test_the_webhook_records_the_payment_and_answers_duplicate_on_re_delivery(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $started = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');
        $ref = (string) $started['payment']->gateway_payment_ref;
        $body = ['ref' => $ref, 'txn' => 'WH-1', 'status' => 'success', 'sig' => LogPaymentGateway::sign($ref, 'WH-1', 'success')];

        $this->postJson('/api/webhooks/payments/sslcommerz', $body)->assertOk()->assertJsonPath('status', 'recorded');
        $this->postJson('/api/webhooks/payments/sslcommerz', $body)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame($invoice->total_paisa, $invoice->refresh()->paid_paisa);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_cancelled_attempt_fails_the_payment_and_leaves_the_bill_untouched(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $started = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Sslcommerz, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');
        $ref = (string) $started['payment']->gateway_payment_ref;

        $driver = app(GatewayManager::class)->driver(PaymentGateway::Sslcommerz);
        $callback = $driver->verifyCallback(Request::create('/cb', 'GET', ['ref' => $ref, 'txn' => 'C-1', 'status' => 'cancel', 'sig' => LogPaymentGateway::sign($ref, 'C-1', 'cancel')]));
        $result = app(SettleGatewayPayment::class)->handle($driver, $callback);

        $this->assertSame(PaymentTxnStatus::Cancelled, $result->payment->status);
        $this->assertSame(0, $invoice->refresh()->paid_paisa);
    }

    public function test_bkash_reads_the_amount_from_execute_and_never_from_the_callback(): void
    {
        config(['billing.gateways.bkash' => ['app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']]);
        app(GatewayManager::class)->forget();

        Http::fake([
            '*token/grant' => Http::response(['id_token' => 'tok', 'expires_in' => 3600]),
            '*checkout/create' => Http::response(['paymentID' => 'PID-1', 'bkashURL' => 'https://bkash.test/pay/PID-1']),
            '*checkout/execute' => Http::response(['transactionStatus' => 'Completed', 'trxID' => 'BK123', 'amount' => '800.00', 'currency' => 'BDT']),
        ]);

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $started = app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Bkash, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');

        $this->assertSame('PID-1', $started['payment']->gateway_payment_ref, 'bKash mints the reference its callback returns');
        $this->assertSame('https://bkash.test/pay/PID-1', $started['session']->redirectUrl);

        $driver = app(GatewayManager::class)->driver(PaymentGateway::Bkash);
        // The callback claims a huge amount; it is ignored entirely — only `paymentID` is read from it.
        $callback = $driver->verifyCallback(Request::create('/cb', 'GET', ['paymentID' => 'PID-1', 'status' => 'success', 'amount' => '999999.00']));
        $this->assertSame('PID-1', $callback->merchantRef, 'only the reference is taken from the callback');

        $result = app(SettleGatewayPayment::class)->handle($driver, $callback);

        $this->assertSame(PaymentTxnStatus::Succeeded, $result->payment->status);
        $this->assertSame(80000, $result->payment->amount_paisa, 'the frozen amount, matching what bKash confirmed');
        $this->assertSame('BK123', $result->payment->gateway_txn_id);
    }

    public function test_a_gateway_that_reports_a_different_amount_credits_nothing(): void
    {
        config(['billing.gateways.bkash' => ['app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']]);
        app(GatewayManager::class)->forget();

        Http::fake([
            '*token/grant' => Http::response(['id_token' => 'tok']),
            '*checkout/create' => Http::response(['paymentID' => 'PID-2', 'bkashURL' => 'https://bkash.test/pay/PID-2']),
            // The gateway says ৳1 was paid for an ৳800 bill.
            '*checkout/execute' => Http::response(['transactionStatus' => 'Completed', 'trxID' => 'BK999', 'amount' => '1.00']),
        ]);

        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(StartOnlinePayment::class)->handle($invoice, PaymentGateway::Bkash, $this->staffActor(), 'https://clinic.test/cb', 'https://clinic.test/cancel');

        $driver = app(GatewayManager::class)->driver(PaymentGateway::Bkash);
        $callback = $driver->verifyCallback(Request::create('/cb', 'GET', ['paymentID' => 'PID-2', 'status' => 'success']));

        try {
            app(SettleGatewayPayment::class)->handle($driver, $callback);
            $this->fail('an amount mismatch must not credit the invoice');
        } catch (GatewayAmountMismatch $e) {
            $this->assertSame('billing.gateway_amount_mismatch', $e->code());
        }

        $payment = Payment::query()->where('gateway_payment_ref', 'PID-2')->firstOrFail();
        $this->assertSame(PaymentTxnStatus::Failed, $payment->status);
        $this->assertSame(0, $invoice->refresh()->paid_paisa);
    }

    public function test_sslcommerz_verifies_the_ipn_hash_before_anything_else(): void
    {
        config(['billing.gateways.sslcommerz' => ['store_id' => 'store', 'store_password' => 'pass']]);
        app(GatewayManager::class)->forget();

        $driver = new SslcommerzGateway(app(GatewayConfig::class));
        $fields = ['tran_id' => 'TR-1', 'val_id' => 'VAL-1', 'status' => 'VALID', 'amount' => '800.00'];
        $verifyKey = 'tran_id,val_id,status,amount,store_passwd';
        $parts = [];

        foreach (explode(',', $verifyKey) as $field) {
            $parts[] = $field.'='.($field === 'store_passwd' ? md5('pass') : $fields[$field]);
        }

        $signed = $fields + ['verify_key' => $verifyKey, 'verify_sign' => md5(implode('&', $parts))];

        $callback = $driver->verifyCallback(Request::create('/ipn', 'POST', $signed));
        $this->assertSame('TR-1', $callback->merchantRef);
        $this->assertSame('VAL-1', $callback->gatewayTxnId);

        // One byte changed anywhere in the signed set and the hash no longer matches.
        $tampered = $signed;
        $tampered['amount'] = '80000.00';
        $this->expectException(GatewaySignatureInvalid::class);
        $driver->verifyCallback(Request::create('/ipn', 'POST', $tampered));
    }

    public function test_nagad_and_bkash_reject_a_callback_with_no_reference(): void
    {
        config(['billing.gateways.nagad' => ['merchant_id' => 'm', 'merchant_number' => 'n', 'public_key' => 'x', 'private_key' => 'y']]);
        app(GatewayManager::class)->forget();

        $this->expectException(GatewaySignatureInvalid::class);
        (new NagadGateway(app(GatewayConfig::class)))->verifyCallback(Request::create('/cb', 'GET', ['status' => 'Success']));
    }

    public function test_the_site_checkout_page_renders_with_server_translated_labels(): void
    {
        // Only SSLCommerz has credentials, so only SSLCommerz is offered — the page never invents a method.
        config(['billing.gateways.sslcommerz' => ['store_id' => 's', 'store_password' => 'p']]);
        app(GatewayManager::class)->forget();

        $booked = $this->book();

        // Public: the patient who just booked online is not logged in.
        auth('web')->logout();

        $this->get(route('site.billing.checkout', ['appointment' => $booked->appointment->public_id], false))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Pay')
                ->where('invoice.due_paisa', 80000)
                ->where('gateways', ['sslcommerz'])
                // Captions are translated server-side, so the site bundle never needs the `billing.` prefix.
                ->has('labels.method_sslcommerz')
                ->has('labels.choose_method'));
    }

    public function test_bkash_rejects_a_callback_with_no_payment_id(): void
    {
        $this->expectException(GatewaySignatureInvalid::class);
        (new BkashGateway(app(GatewayConfig::class)))->verifyCallback(Request::create('/cb', 'GET', ['status' => 'success']));
    }
}
