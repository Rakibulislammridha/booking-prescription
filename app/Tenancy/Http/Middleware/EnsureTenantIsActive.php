<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Domain\SaaS\Enums\TenantStatus;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * trial|active|past_due are served (past_due with a dunning banner); suspended → 402 site/Suspended; cancelled → 404.
 */
final class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return $next($request);
        }

        return match ($tenant->status) {
            TenantStatus::Trial, TenantStatus::Active => $next($request),
            TenantStatus::PastDue => $this->withDunningBanner($request, $next),
            TenantStatus::Suspended => $this->suspended($request),
            TenantStatus::Cancelled => abort(404),
        };
    }

    private function withDunningBanner(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $request->session()->now('flash.warning', __('tenancy.past_due_banner'));
        }

        return $next($request);
    }

    private function suspended(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('tenancy.suspended'), 'code' => 'tenancy.suspended'], 402);
        }

        Inertia::setRootView('site');

        return Inertia::render('Suspended', ['tenant' => ['name' => Tenancy::current()?->name]])
            ->toResponse($request)
            ->setStatusCode(402);
    }
}
