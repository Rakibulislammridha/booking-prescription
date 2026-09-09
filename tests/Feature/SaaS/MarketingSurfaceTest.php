<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Actions\Plans\ArchivePlan;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Support\Changelog;
use App\Domain\SaaS\Support\DocsLibrary;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** The commercial front door: home, pricing from real plan rows, the docs shell, the changelog, and paying. */
final class MarketingSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);
    }

    public function test_the_home_page_renders_on_the_central_host_with_the_real_plans(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Central/Home')
            ->has('plans.0.code')
            ->has('highlights')
            ->has('links.pricing')
            ->has('copy'));
    }

    /**
     * Central pages are rendered by the SITE bundle, which carries the `site` Ziggy group — and no `site.*` route
     * is registered on the bare central host, so before `config/ziggy.php` grew a `central` group these pages
     * received an EMPTY route list and could only ever use link props. The group is the fix; this pins it, and
     * pins the isolation that makes it worth having (a central page cannot name a tenant route by accident).
     */
    public function test_central_pages_receive_their_own_ziggy_group(): void
    {
        /** @var array<string, mixed> $ziggy */
        $ziggy = $this->props('/', 'ziggy');
        /** @var array<string, mixed> $routes */
        $routes = $ziggy['routes'];

        $this->assertArrayHasKey('central.pricing', $routes);
        $this->assertArrayHasKey('central.onboarding.store', $routes);
        $this->assertArrayNotHasKey('site.home', $routes);
        $this->assertArrayNotHasKey('panel.dashboard', $routes);
        $this->assertArrayNotHasKey('super.dashboard', $routes);
        $this->assertSame('bp.test', $ziggy['url'] === null ? null : parse_url((string) $ziggy['url'], PHP_URL_HOST));

        // …and the pages still work, links prop and all.
        $this->get('/pricing')->assertOk();
        $this->get('/signup')->assertOk();
        $this->get('/docs')->assertOk();
    }

    public function test_pricing_is_driven_by_the_plan_rows_and_hides_archived_and_private_plans(): void
    {
        $this->get('/pricing')->assertOk()->assertInertia(fn ($page) => $page->component('Central/Pricing'));

        $codes = collect($this->props('/pricing', 'plans'))->pluck('code')->all();
        $this->assertContains('starter', $codes);
        $this->assertContains('pro', $codes);
        $this->assertNotContains('telemedicine', $codes, 'add-ons are listed separately');

        $addons = collect($this->props('/pricing', 'addons'))->pluck('code')->all();
        $this->assertContains('telemedicine', $addons);

        // A limit shown to a prospect is literally the row that will be enforced against them.
        /** @var array<string, mixed> $pro */
        $pro = collect($this->props('/pricing', 'plans'))->firstWhere('code', 'pro');
        $this->assertSame(400000, $pro['price_monthly_paisa']);
        $this->assertIsInt($pro['price_monthly_paisa']);
        /** @var array<int, array<string, mixed>> $limits */
        $limits = $pro['limits'];
        $branches = collect($limits)->firstWhere('key', 'branches');
        $this->assertSame(5, $branches['value']);
        $appointments = collect($limits)->firstWhere('key', 'appointments_monthly');
        $this->assertNull($appointments['value'], 'unlimited must reach the page as null, not as 0');
        /** @var array<int, array<string, mixed>> $toggles */
        $toggles = $pro['toggles'];
        $this->assertTrue(collect($toggles)->firstWhere('key', 'whatsapp')['enabled']);

        // Archiving a plan removes it from the pricing page without touching anyone's subscription.
        app(ArchivePlan::class)->handle(Plan::query()->where('code', 'pro')->firstOrFail());
        $this->assertNotContains('pro', collect($this->props('/pricing', 'plans'))->pluck('code')->all());
    }

    public function test_the_docs_shell_serves_translated_bodies_from_the_server(): void
    {
        $this->get('/docs')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Central/Docs')
            ->where('current.slug', DocsLibrary::default())
            ->has('sections.0.title')
            ->has('current.blocks.0.text'));

        foreach (DocsLibrary::slugs() as $slug) {
            $this->get("/docs/{$slug}")->assertOk()->assertInertia(fn ($page) => $page->where('current.slug', $slug));
        }

        $this->get('/docs/no-such-section')->assertNotFound();

        // The bodies are already translated: the page never builds a lang key.
        $blocks = $this->props('/docs', 'current')['blocks'];
        $this->assertSame(__('saas.handbook.getting-started.para.1'), $blocks[0]['text']);
        $this->assertNotSame('saas.handbook.getting-started.para.1', $blocks[0]['text']);
    }

    /**
     * Both content catalogues build their keys DYNAMICALLY (`saas.handbook.<slug>.title`,
     * `saas.release.<version>.<n>`), so `lang:check` — which only sees literal `__('…')` — cannot cover them.
     * A missing key renders as the raw key on the public site, which is exactly how one shipped once.
     */
    public function test_every_documentation_and_changelog_string_actually_exists_in_both_locales(): void
    {
        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);
            $url = fn (string $slug): string => '/docs/'.$slug;

            foreach (DocsLibrary::index($url) as $section) {
                $this->assertStringNotContainsString('saas.', $section['title'], "docs title for {$section['slug']} is a raw key in {$locale}");
            }

            foreach (DocsLibrary::slugs() as $slug) {
                foreach (DocsLibrary::section($slug, $url)['blocks'] as $block) {
                    foreach ([$block['text'] ?? null, ...($block['items'] ?? [])] as $text) {
                        if ($text !== null) {
                            $this->assertStringNotContainsString('saas.handbook.', (string) $text, "a docs block of {$slug} is a raw key in {$locale}");
                        }
                    }
                }
            }

            foreach (Changelog::entries() as $entry) {
                foreach ($entry['changes'] as $change) {
                    $this->assertStringNotContainsString('saas.release.', $change['text'], "a changelog entry of {$entry['version']} is a raw key in {$locale}");
                }
            }
        }

        app()->setLocale('bn');
    }

    public function test_the_changelog_lists_releases_newest_first_with_translated_entries(): void
    {
        $this->get('/changelog')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Central/Changelog')
            ->where('entries.0.version', Changelog::latestVersion())
            ->has('entries.0.changes.0.text'));

        $entries = $this->props('/changelog', 'entries');
        $this->assertSame(__('saas.release.1_4_0.1'), $entries[0]['changes'][0]['text']);
    }

    public function test_the_language_switch_works_without_a_tenant(): void
    {
        $this->patch('/locale', ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', session('locale'));

        $copy = $this->props('/pricing', 'copy');
        $this->assertSame(__('saas.nav.pricing', [], 'en'), $copy['nav.pricing']);
    }

    public function test_a_platform_invoice_is_payable_from_the_central_host_even_when_the_clinic_is_suspended(): void
    {
        config(['billing.gateways.driver' => 'log']);
        $tenant = $this->tenant('a');
        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => CarbonImmutable::now()])->save();
        $invoice = $this->invoiceFor();

        $url = URL::signedRoute('central.billing.invoice', ['invoice' => $invoice->public_id]);

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Central/Billing/Invoice')
            ->where('invoice.number', $invoice->number)
            ->where('invoice.due_paisa', 400000)
            ->where('suspended', true)
            ->has('pay_url')
            ->has('gateways.0.value'));

        // Unsigned is refused: the page names a clinic and an amount.
        $this->get(route('central.billing.invoice', ['invoice' => $invoice->public_id], false))->assertForbidden();
    }

    public function test_paying_from_the_central_host_settles_the_invoice_and_brings_the_clinic_back(): void
    {
        config(['billing.gateways.driver' => 'log']);
        $tenant = $this->tenant('a');
        Subscription::query()->whereKey($tenant->current_subscription_id)->update(['status' => 'suspended']);
        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => CarbonImmutable::now()])->save();
        $invoice = $this->invoiceFor();

        $pay = URL::signedRoute('central.billing.pay', ['invoice' => $invoice->public_id]);
        $redirect = $this->post($pay, ['gateway' => 'sslcommerz']);
        $redirect->assertRedirect();

        // Follow the gateway's return exactly as a browser would.
        $callbackUrl = (string) $redirect->headers->get('Location');
        $this->get($callbackUrl)->assertRedirect();

        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
    }

    private function invoiceFor(): SubscriptionInvoice
    {
        $tenant = $this->tenant('a');

        return SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $tenant->current_subscription_id,
            'number' => 'SI-2026-000999',
            'status' => SubscriptionInvoiceStatus::Overdue,
            'period_start' => CarbonImmutable::now()->toDateString(),
            'period_end' => CarbonImmutable::now()->addMonth()->toDateString(),
            'subtotal_paisa' => 400000,
            'total_paisa' => 400000,
            'paid_paisa' => 0,
            'line_items' => [['description' => 'Pro · monthly', 'quantity' => 1, 'unit_paisa' => 400000, 'total_paisa' => 400000, 'feature_key' => null]],
            'issued_at' => CarbonImmutable::now()->subDays(20),
            'due_at' => CarbonImmutable::now()->subDays(13),
            'dunning_step' => 3,
        ]);
    }

    /** @return array<int|string, mixed> */
    private function props(string $url, string $key): array
    {
        /** @var array<int|string, mixed> $props */
        $props = (array) $this->withHeaders($this->inertiaHeaders())->get($url)->json("props.{$key}");

        return $props;
    }
}
