<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\SaaS\Actions\Onboarding\SignUpTenant;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\CentralCopy;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Domain\Tenancy\Exceptions\ProvisioningFailed;
use App\Domain\Tenancy\Exceptions\SlugReserved;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\SignUpRequest;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sign-up wizard (BRIEF §5.M): sign up → clinic details → plan/trial → provisioning, and a hand-off to the
 * new clinic's own panel on its own subdomain.
 *
 * Provisioning is synchronous on purpose. It takes a few seconds (create schema → run every tenant migration →
 * seed roles → first branch → admin user), and a clinic manager who has just typed their details deserves either
 * a working panel link or an error — not a "we'll email you" that hides a failure. `ProvisionTenant` rolls the
 * schema and the `public` rows back on any failure, so a refused sign-up leaves nothing behind and the slug is
 * free again.
 */
final class OnboardingController extends Controller
{
    use BuildsCentralLinks;

    public function create(Request $request, PlanCatalog $catalog, PlatformSettings $settings): Response
    {
        return Inertia::render('Central/Onboarding/Signup', [
            'plans' => $catalog->publicPlans(),
            'links' => $this->centralLinks(),
            'platform' => $this->platformProps(),
            'central_domain' => (string) config('tenancy.central_domain'),
            'selected_plan' => $request->query('plan') === null ? null : (string) $request->query('plan'),
            'slug_suggestion' => '',
            // The platform's defaults for the two fields most clinics never touch (`onboarding.default_*`).
            'defaults' => [
                'locale' => (string) $settings->get(PlatformSettingsRegistry::DEFAULT_LOCALE),
                'timezone' => (string) $settings->get(PlatformSettingsRegistry::DEFAULT_TIMEZONE),
                'trial_days' => (int) $settings->get(PlatformSettingsRegistry::TRIAL_DAYS),
            ],
            'copy' => CentralCopy::for('signup'),
        ]);
    }

    public function store(SignUpRequest $request, SignUpTenant $signUp): RedirectResponse
    {
        // `onboarding.signup_open` off: the wizard already shows the closed message instead of the form; a POST
        // that arrives anyway (an old tab, a script) is refused with the same message and provisions nothing.
        if (! $this->platformProps()['signup_open']) {
            throw ValidationException::withMessages(['clinic_name' => $this->platformProps()['signup_closed_message']]);
        }

        try {
            $tenant = $signUp->handle($request->toData());
        } catch (SlugTaken|SlugReserved) {
            throw ValidationException::withMessages(['slug' => __('saas.onboarding.slug_taken')]);
        } catch (ProvisioningFailed $e) {
            report($e);
            Tenancy::check() && Tenancy::end();

            throw ValidationException::withMessages(['clinic_name' => __('saas.onboarding.failed')]);
        }

        return redirect()->route('central.onboarding.done', ['tenant' => $tenant->public_id]);
    }

    public function done(Tenant $tenant): Response
    {
        abort_if($tenant->provisioned_at === null, 404);

        return Inertia::render('Central/Onboarding/Done', [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'panel_url' => $this->tenantUrl($tenant),
                'admin_email' => $tenant->owner_email,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            ],
            'links' => $this->centralLinks(),
            'platform' => $this->platformProps(),
            'copy' => CentralCopy::for('done'),
        ]);
    }
}
