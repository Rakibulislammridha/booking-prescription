<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\ForgetSetting;
use App\Domain\Clinic\Actions\UpdateBranding;
use App\Domain\Clinic\Actions\UpdateSetting;
use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Panel\Clinic\UpdateBrandingRequest;
use App\Http\Requests\Panel\Clinic\UpdateSettingsRequest;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A / SCHEMA Appendix B — the settings screen is generated from `SettingsRegistry`, never hand-written:
 * the page receives the definition (type, default, min, max, options) of every key and renders the matching input,
 * so a key added to the registry appears here with no frontend change. Values are written through the `Settings`
 * service, which type-checks against the same registry and drops the per-tenant cache.
 *
 * Branding sits on the same screen but on the central row (`public.tenants.name/locale/branding`): the colours it
 * saves become the `--tenant-*` CSS variables of the public booking site.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings, private readonly ClinicUploads $uploads) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Setting::class);
        /** @var User $user */
        $user = $request->user('web');
        $tenant = Tenancy::current();
        $branding = (array) ($tenant->branding ?? []);

        return Inertia::render('Clinic/Settings/Index', [
            'registry' => self::registry(),
            'groups' => self::groups(),
            'values' => $this->settings->all(),
            'branding' => [
                'name' => $tenant->name ?? '',
                'name_bn' => isset($branding['name_bn']) ? (string) $branding['name_bn'] : null,
                'locale' => $tenant->locale->value ?? 'bn',
                'primary_color' => isset($branding['primary_color']) ? (string) $branding['primary_color'] : null,
                'accent_color' => isset($branding['accent_color']) ? (string) $branding['accent_color'] : null,
                'on_primary_color' => isset($branding['on_primary_color']) ? (string) $branding['on_primary_color'] : null,
                'logo_url' => HandleInertiaRequests::publicUrl($branding['logo_path'] ?? null),
                'timezone' => $tenant->timezone ?? (string) config('app.timezone'),
            ],
            'can' => ['manage' => $user->can('create', Setting::class)],
        ]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSetting $update, ForgetSetting $forget): RedirectResponse
    {
        $actor = Actor::fromRequest($request);

        DB::transaction(function () use ($request, $update, $forget, $actor): void {
            foreach ($request->values() as $key => $value) {
                $update->handle($key, $value, $actor);
            }

            foreach ($request->removals() as $key) {
                $forget->handle($key);
            }
        });

        return back()->with('flash.success', __('clinic.settings.flash.saved'));
    }

    public function branding(UpdateBrandingRequest $request, UpdateBranding $update): RedirectResponse
    {
        $tenant = Tenancy::current();
        abort_if($tenant === null, 404);

        $logo = $request->file('logo');
        $data = $request->toData()->withLogoPath($logo instanceof UploadedFile ? $this->uploads->brandingLogo($logo) : null);
        $update->handle($tenant, $data, Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.settings.flash.branding_saved'));
    }

    /**
     * The registry, wire-shaped: one entry per key with everything the input needs to validate itself.
     *
     * `secret` travels with the definition so the field renders itself as a credential (password input, "leave
     * blank to keep", a Remove affordance) for the same reason every other flag does: adding a key to the
     * registry must not need a frontend change. The VALUE of a secret is never in this payload — `values` carries
     * `Settings::all()`, which masks them, and `is_set` is derived from that mask on the client.
     *
     * @return array<string, array{type: string, default: mixed, options: array<int, string>|null, min: int|float|null, max: int|float|null, secret: bool}>
     */
    private static function registry(): array
    {
        $out = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            $out[$key] = [
                'type' => $definition['type'],
                'default' => $definition['default'],
                'options' => $definition['options'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'secret' => ($definition['secret'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * Keys grouped by their first segment, in registry order — the page's section list.
     *
     * @return array<int, array{prefix: string, keys: array<int, string>}>
     */
    private static function groups(): array
    {
        $groups = [];

        foreach (array_keys(SettingsRegistry::all()) as $key) {
            $groups[explode('.', $key, 2)[0]][] = $key;
        }

        return array_map(fn (string $prefix) => ['prefix' => $prefix, 'keys' => $groups[$prefix]], array_keys($groups));
    }
}
