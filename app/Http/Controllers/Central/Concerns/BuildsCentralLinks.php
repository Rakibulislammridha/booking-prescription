<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Concerns;

use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Central pages are served on the bare platform host and are rendered by the SITE bundle, whose Ziggy group is
 * `site.*` + `api.*` — it does not carry `central.*`. Rather than widen a foundation-owned config, every central
 * page receives its URLs as a prop. It is also simply better: the page cannot build a URL that does not exist.
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
