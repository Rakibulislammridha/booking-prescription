<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Tenants\ToggleTenantFeature;
use App\Domain\SaaS\Actions\Tenants\UpdateTenantLimits;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-tenant feature toggles and negotiated caps (BRIEF §5.M). Both write `subscriptions.feature_overrides` and
 * purge the tenant's resolved Pennant rows, so `Feature::active('telemedicine')` inside the clinic's next
 * request already agrees.
 */
final class EntitlementController extends Controller
{
    public function feature(Request $request, Tenant $tenant, ToggleTenantFeature $toggle): RedirectResponse
    {
        $validated = $request->validate([
            'feature' => ['required', Rule::in(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()))],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $toggle->handle(
            $tenant,
            PlanFeatureKey::from((string) $validated['feature']),
            $request->exists('enabled') && $validated['enabled'] !== null ? $request->boolean('enabled') : null,
        );

        return back()->with('flash.success', __('saas.tenants.flash.feature_saved'));
    }

    public function limits(Request $request, Tenant $tenant, UpdateTenantLimits $update): RedirectResponse
    {
        $keys = array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits());

        $validated = $request->validate([
            'limits' => ['array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'clear' => ['array'],
            'clear.*' => [Rule::in($keys)],
        ]);

        /** @var array<string, int|null> $limits */
        $limits = array_intersect_key((array) ($validated['limits'] ?? []), array_flip($keys));

        $update->handle($tenant, $limits, array_values((array) ($validated['clear'] ?? [])));

        return back()->with('flash.success', __('saas.tenants.flash.limits_saved'));
    }
}
