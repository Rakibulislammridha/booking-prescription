<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The marketing surface's counterpart of `App\Tenancy\Http\Middleware\SetTenantLocale`.
 *
 * The tenant version falls back to `tenants.locale`; on the central host there is no tenant, so the choice is the
 * visitor's session, then their browser's `Accept-Language`, then Bangla — this is a Bangladeshi product and an
 * unannounced visitor is far more likely to read Bangla than English.
 *
 * Applied inside `routes/central/*.php` rather than in the `central` middleware group, because
 * `bootstrap/app.php` is foundation-owned and this is one module's need, not the surface's.
 */
final class SetCentralLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale = is_string($session) ? $session : $this->fromBrowser($request);

        app()->setLocale(in_array($locale, ['bn', 'en'], true) ? $locale : 'bn');

        return $next($request);
    }

    private function fromBrowser(Request $request): string
    {
        $preferred = $request->getPreferredLanguage(['bn', 'en']);

        return is_string($preferred) ? $preferred : 'bn';
    }
}
