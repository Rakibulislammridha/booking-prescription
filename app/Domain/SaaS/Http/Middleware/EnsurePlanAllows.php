<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Http\Middleware;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Exceptions\FeatureNotInPlan;
use App\Domain\SaaS\Exceptions\PlanLimitExceeded;
use App\Domain\SaaS\Services\PlanLimits;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level plan gate — the `plan:` alias `bootstrap/app.php` already registers.
 *
 *   Route::middleware('plan:telemedicine')          a module toggle: 402 / redirect when the plan excludes it
 *   Route::middleware('plan:branches')              a numeric cap: refuses the request that would exceed it
 *   Route::middleware('plan:appointments_monthly,5')  … by more than N
 *
 * It is the SECOND line, not the only one. A route middleware protects the screens a module owns; the atomic
 * reservation in the usage observers protects every other write path and is what holds under a race. The
 * middleware exists so a whole section can be closed off cheaply and with a message, before any work is done.
 *
 * A browser navigation is answered with a redirect to the subscription page carrying the reason, because a raw
 * 402 page tells a clinic manager nothing about what to do next; JSON and Inertia XHR get the domain error, which
 * the global renderer turns into `{"code":"saas.limit.doctors"}`.
 */
final class EnsurePlanAllows
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $tenant = Tenancy::current();

        if ($tenant === null || $arguments === []) {
            return $next($request);
        }

        $key = (string) $arguments[0];
        $quantity = max(1, (int) ($arguments[1] ?? 1));

        try {
            $feature = PlanFeatureKey::tryFrom($key) ?? PlanFeatureKey::fromFeatureName($key);

            if ($feature !== null && $feature->isToggle()) {
                $this->limits->assertFeature($tenant, $feature);

                return $next($request);
            }

            $metric = UsageMetric::tryFrom($key) ?? $feature?->metric();

            if ($metric !== null) {
                $this->limits->assertCanAdd($tenant, $metric, $quantity);
            }
        } catch (FeatureNotInPlan|PlanLimitExceeded $e) {
            return $this->refuse($request, $e->getMessage(), $e->code(), $e->status());
        }

        return $next($request);
    }

    private function refuse(Request $request, string $message, string $code, int $status): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia') !== null) {
            return response()->json(['message' => $message, 'code' => $code], $status);
        }

        return redirect()->route('panel.saas.subscription.index')->with('flash.error', $message);
    }
}
