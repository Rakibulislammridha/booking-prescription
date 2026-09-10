<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Queries\BillingTenantPanel;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform-wide invoice and payment desk: raise / issue / void / record a payment by invoice alone, the
 * manual reference as the idempotency key, the printable invoice and its PDF path, the CSV exports, and the
 * `billing` prop the tenant page renders — with every mutation on the audit trail and no tenancy left behind.
 */
final class BillingConsoleTest extends TestCase
{
    public function test_the_billing_screens_are_closed_to_guests_and_open_to_operators_without_a_tenancy(): void
    {
        $tenant = $this->tenant('a');
        $urls = [
            route('super.billing.index', absolute: false),
            route('super.billing.subscriptions.index', absolute: false),
            route('super.billing.invoices.index', absolute: false),
            route('super.billing.payments.index', absolute: false),
            route('super.billing.dunning.index', absolute: false),
        ];

        $this->asCentral();
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('super.login', absolute: false));
        }
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id])->assertRedirect(route('super.login', absolute: false));

        $this->actingAsSuper();
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
            $this->assertFalse(Tenancy::check(), "{$url} left a tenancy initialised");
            $this->assertSame('public', DB::scalar('show search_path'));
        }
    }

    public function test_raise_issue_pay_and_the_same_reference_twice_records_one_payment(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->activeTenant(150000);

        // Raise a DRAFT, then issue it from the desk: two audit rows under the operator's name.
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id, 'issue' => false])->assertRedirect();
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SubscriptionInvoiceStatus::Draft, $invoice->status);
        $this->assertSame(150000, $invoice->total_paisa);

        $this->post(route('super.billing.invoices.issue', ['invoice' => $invoice->public_id], false))->assertRedirect();
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->refresh()->status);

        // Raising again for the same period is the same invoice, not a second one.
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id])->assertRedirect();
        $this->assertSame(1, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());

        // A reference is required — it is the idempotency key.
        $this->post(route('super.billing.invoices.pay', ['invoice' => $invoice->public_id], false), ['amount_paisa' => 150000, 'method' => 'bank_transfer'])
            ->assertSessionHasErrors('reference');

        $payload = ['amount_paisa' => 100000, 'method' => 'bank_transfer', 'reference' => 'DBBL-77123'];
        $this->post(route('super.billing.invoices.pay', ['invoice' => $invoice->public_id], false), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('super.billing.invoices.pay', ['invoice' => $invoice->public_id], false), $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->count(), 'the same transfer pasted twice is one payment');
        $this->assertSame(100000, (int) $invoice->refresh()->getAttribute('paid_paisa'));
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->status, 'partly paid stays open');
        $this->assertSame($admin->id, (int) SubscriptionPayment::query()->where('subscription_invoice_id', $invoice->id)->value('recorded_by_super_admin_id'));

        // The second, replayed request is audited as such.
        $logs = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('auditable_type', (new SubscriptionPayment)->getMorphClass())->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertFalse($logs[0]->getAttribute('after')['replayed']);
        $this->assertTrue($logs[1]->getAttribute('after')['replayed']);

        // A different reference settles the rest, and a paid invoice cannot be voided.
        $this->post(route('super.billing.invoices.pay', ['invoice' => $invoice->public_id], false), ['amount_paisa' => 50000, 'method' => 'cash', 'reference' => 'RCPT-9'])->assertRedirect();
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->refresh()->status);
        $this->post(route('super.billing.invoices.void', ['invoice' => $invoice->public_id], false), ['reason' => 'oops'])->assertSessionHasErrors('domain');

        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Create->value)->where('auditable_id', $invoice->id)->exists());
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('auditable_id', $invoice->id)->exists());
    }

    public function test_an_unpaid_invoice_can_be_voided_with_a_reason_and_the_tenant_scoped_desk_checks_the_tenant(): void
    {
        $this->actingAsSuper();
        $tenant = $this->activeTenant(150000);
        $other = $this->tenant('b');

        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id])->assertRedirect();
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();

        // The tenant-scoped routes refuse another clinic's invoice outright.
        $this->post(route('super.tenants.invoices.void', ['tenant' => $other->public_id, 'invoice' => $invoice->public_id], false), ['reason' => 'wrong desk'])->assertNotFound();
        $this->post(route('super.tenants.invoices.pay', ['tenant' => $other->public_id, 'invoice' => $invoice->public_id], false), ['amount_paisa' => 1, 'method' => 'cash', 'reference' => 'x'])->assertNotFound();

        $this->post(route('super.billing.invoices.void', ['invoice' => $invoice->public_id], false), [])->assertSessionHasErrors('reason');
        $this->post(route('super.billing.invoices.void', ['invoice' => $invoice->public_id], false), ['reason' => 'raised twice by mistake'])->assertRedirect();
        $this->assertSame(SubscriptionInvoiceStatus::Void, $invoice->refresh()->status);

        $log = AuditLogCentral::query()->where('auditable_id', $invoice->id)->where('action', CentralAuditAction::Update->value)->latest('id')->firstOrFail();
        $this->assertSame('raised twice by mistake', $log->getAttribute('after')['reason']);
    }

    public function test_the_invoice_list_filters_and_the_print_and_pdf_paths_render_the_stored_row(): void
    {
        $this->actingAsSuper();
        $tenant = $this->activeTenant(250050);
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id])->assertRedirect();
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $invoice->forceFill(['due_at' => CarbonImmutable::now()->subDays(2)])->save();

        $this->get(route('super.billing.invoices.index', absolute: false).'?tenant='.$tenant->public_id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Billing/Invoices')
                ->where('invoices.0.number', $invoice->number)
                ->where('invoices.0.total_paisa', 250050)
                ->where('invoices.0.due_paisa', 250050)
                ->where('invoices.0.is_past_due', true)
                ->where('invoices.0.tenant.slug', $tenant->slug)
                ->where('meta.total', 1)
                ->has('status_counts.past_due'));

        $this->get(route('super.billing.invoices.index', absolute: false).'?status=paid&tenant='.$tenant->public_id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices', []));

        $this->get(route('super.billing.invoices.index', absolute: false).'?status=past_due&q='.$invoice->number)->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.0.number', $invoice->number));

        // Print: the stored row, bilingual, with the money formatted from integer paisa.
        $html = $this->get(route('super.billing.invoices.print', ['invoice' => $invoice->public_id], false))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('৳2,500.50', $html);
        $this->assertStringContainsString($tenant->name, $html);
        $this->assertStringContainsString('সাবস্ক্রিপশন চালান', $html);
        $this->assertTrue(AuditLogCentral::query()->where('auditable_id', $invoice->id)->where('action', CentralAuditAction::View->value)->exists(), 'opening a customer\'s invoice is a read that is logged');

        // PDF: with no Chrome on the box the same HTML is served rather than a 500; with Chrome, a PDF.
        config(['prescription.pdf.chrome_path' => '/nonexistent/chrome']);
        $fallback = $this->get(route('super.billing.invoices.pdf', ['invoice' => $invoice->public_id], false))->assertOk();
        $this->assertStringStartsWith('text/html', (string) $fallback->headers->get('Content-Type'));
    }

    public function test_the_payments_list_and_the_three_csv_exports_stream_and_are_audited(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->activeTenant(150000);
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $tenant->public_id])->assertRedirect();
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->post(route('super.billing.invoices.pay', ['invoice' => $invoice->public_id], false), ['amount_paisa' => 150000, 'method' => 'bkash', 'reference' => 'TRX-ABC-1'])->assertRedirect();

        $this->get(route('super.billing.payments.index', absolute: false).'?method=bkash&tenant='.$tenant->public_id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Billing/Payments')
                ->where('payments.0.gateway_txn_id', 'TRX-ABC-1')
                ->where('payments.0.amount_paisa', 150000)
                ->where('payments.0.status', 'succeeded')
                ->where('payments.0.invoice.number', $invoice->number)
                ->has('method_totals.bkash'));
        $this->get(route('super.billing.payments.index', absolute: false).'?method=cash&tenant='.$tenant->public_id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('payments', []));

        foreach (['subscriptions', 'invoices', 'payments'] as $kind) {
            $response = $this->get(route('super.billing.export', ['kind' => $kind, 'tenant' => $tenant->public_id], false))->assertOk();
            $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
            $csv = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM, or Excel shows Bangla as mojibake');
            $this->assertStringContainsString($kind === 'subscriptions' ? $tenant->slug : $invoice->number, $csv);
            $this->assertStringContainsString('1500.00', $csv, 'money is exported in taka as an exact decimal string');
        }

        $this->get(route('super.billing.export', ['kind' => 'nope'], false))->assertNotFound();

        $exports = AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', CentralAuditAction::Export->value)->get();
        $this->assertCount(3, $exports);
        $this->assertSame('billing.invoices', $exports[1]->getAttribute('after')['kind']);
    }

    public function test_the_tenant_billing_panel_reads_one_clinics_money_only_and_without_its_schema(): void
    {
        $a = $this->activeTenant(150000, 'a');
        $b = $this->activeTenant(400000, 'b');
        $this->actingAsSuper();
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $a->public_id])->assertRedirect();
        $this->post(route('super.billing.invoices.store', absolute: false), ['tenant' => $b->public_id])->assertRedirect();

        $panel = app(BillingTenantPanel::class)->for($a);

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertSame('active', $panel['subscription']['status']);
        $this->assertSame(150000, $panel['subscription']['price_paisa']);
        $this->assertCount(1, $panel['invoices']);
        $this->assertSame(150000, $panel['invoices'][0]['total_paisa']);
        $this->assertSame($a->slug, $panel['invoices'][0]['tenant']['slug']);
        $this->assertSame(150000, $panel['arrears_paisa']);
        $this->assertSame(1, $panel['arrears_invoices']);
        $this->assertSame([], $panel['payments']);
        $this->assertSame([], $panel['dunning'], 'not yet due: nothing on the ladder');
        $this->assertNotNull($panel['next_invoice_at']);

        $this->assertSame(400000, app(BillingTenantPanel::class)->for($b)['arrears_paisa'], 'each clinic sees only its own');
    }

    private function activeTenant(int $priceMonthly, string $which = 'a'): Tenant
    {
        $tenant = $this->tenant($which);
        Subscription::query()->whereKey($tenant->current_subscription_id)->update([
            'status' => SubscriptionStatus::Active->value,
            'price_paisa' => $priceMonthly,
            'current_period_start' => CarbonImmutable::now()->subDay(),
            'current_period_end' => CarbonImmutable::now()->addMonth(),
        ]);
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        return $tenant->refresh();
    }
}
