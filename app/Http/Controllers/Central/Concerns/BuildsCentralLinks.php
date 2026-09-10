<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Concerns;

use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * URL helpers for the central (marketing / onboarding / invoice) pages on the bare platform host.
 *
 * `config/ziggy.php` now has a `central` group, so these pages DO receive `central.*` through Ziggy like every
 * other surface and `route()` works on them. `centralLinks()` survives because it is a different thing: it is the
 * small, named set of links the marketing shell renders on every page, passed once as a prop rather than resolved
 * key by key in the markup. `tenantUrl()` and `signedInvoiceUrl()` cannot be Ziggy at all — one crosses to another
 * HOST (the clinic's own), the other carries a signature that only the server can produce.
 */
trait BuildsCentralLinks
{
    /**
     * A tenant's own panel URL on ITS host, keeping the current port. Dev and CI serve the platform on
     * :8000/:8090, and a hand-off link that dropped the port would send a brand-new clinic to a dead address.
     */
    protected function tenantUrl(Tenant $tenant, string $path = '/panel'): string
    {
        $request = request();
        $port = $request->getPort();
        $scheme = $request->getScheme();
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$tenant->primaryHost().($default ? '' : ':'.$port).$path;
    }

    /**
     * A signed link to a platform invoice. Signed rather than authenticated because the recipient is a clinic
     * OWNER reading a dunning email, who may not have a panel session and — if the tenant is already suspended —
     * cannot get one. 60 days is longer than the dunning ladder plus the grace period, so a link in the first
     * reminder still works when the final notice arrives.
     */
    protected function signedInvoiceUrl(SubscriptionInvoice $invoice, string $route = 'central.billing.invoice'): string
    {
        return URL::signedRoute($route, ['invoice' => $invoice->public_id], CarbonImmutable::now()->addDays(60));
    }

    /**
     * The platform's own identity for the marketing shell (`PlatformSettingsRegistry`): name, support contacts,
     * the maintenance banner and whether sign-up is open. Passed as the `platform` prop on every central page so
     * the shell reads it like `links` — a console change shows on the very next request.
     *
     * @return array{name: string, support_email: string, support_phone: string, maintenance_banner: string, signup_open: bool, signup_closed_message: string}
     */
    protected function platformProps(): array
    {
        $settings = app(PlatformSettings::class);
        $closedMessage = trim((string) $settings->get(PlatformSettingsRegistry::SIGNUP_CLOSED_MESSAGE));

        return [
            'name' => (string) $settings->get(PlatformSettingsRegistry::PLATFORM_NAME),
            'support_email' => (string) $settings->get(PlatformSettingsRegistry::SUPPORT_EMAIL),
            'support_phone' => (string) $settings->get(PlatformSettingsRegistry::SUPPORT_PHONE),
            'maintenance_banner' => trim((string) $settings->get(PlatformSettingsRegistry::MAINTENANCE_BANNER)),
            'signup_open' => (bool) $settings->get(PlatformSettingsRegistry::SIGNUP_OPEN),
            'signup_closed_message' => $closedMessage !== '' ? $closedMessage : (string) __('saas.onboarding.closed'),
        ];
    }

    /** @return array<string, string> */
    protected function centralLinks(): array
    {
        return [
            'home' => route('central.home'),
            'pricing' => route('central.pricing'),
            'docs' => route('central.docs.index'),
            'changelog' => route('central.changelog'),
            'signup' => route('central.onboarding.create'),
            'signup_store' => route('central.onboarding.store'),
            'locale' => route('central.locale'),
        ];
    }
}
