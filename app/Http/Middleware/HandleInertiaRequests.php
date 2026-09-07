<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Middleware;
use Laravel\Pennant\Feature;
use stdClass;
use Tighten\Ziggy\Ziggy;

/**
 * Shared props contract — ARCHITECTURE §7.4 (resources/js/shared/types/shared-props.d.ts).
 *
 * share() is evaluated by Inertia's middleware BEFORE the route middleware that follow it (SetTenantLocale, auth,
 * SetActiveBranch). Everything that depends on them — auth, branch, branches, locale, flash — is therefore a closure,
 * resolved when the response is rendered.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'site';

    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** 'panel' for panel.* and super.* routes, otherwise 'site' (ARCHITECTURE §7.3). */
    public function rootView(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return str_starts_with($name, 'panel.') || str_starts_with($name, 'super.') ? 'panel' : 'site';
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $tenant = Tenancy::current();
        $group = $this->ziggyGroup($request);

        return [
            ...parent::share($request),
            'auth' => fn () => $this->auth($request),
            'tenant' => $tenant === null ? null : $this->tenant($tenant),
            'branch' => fn () => $this->branch(),
            'branches' => fn () => $this->branches($request),
            'locale' => fn () => app()->getLocale(),
            'flash' => [
                'success' => fn () => $request->hasSession() ? $request->session()->get('flash.success') : null,
                'error' => fn () => $request->hasSession() ? $request->session()->get('flash.error') : null,
                'warning' => fn () => $request->hasSession() ? $request->session()->get('flash.warning') : null,
                'info' => fn () => $request->hasSession() ? $request->session()->get('flash.info') : null,
            ],
            'features' => Inertia::once(fn () => $tenant === null ? new stdClass : self::asObject($this->features())),
            'ziggy' => Inertia::once(fn () => (new Ziggy($group, $request->getSchemeAndHttpHost()))->toArray()),
            'csrf_token' => $request->hasSession() ? csrf_token() : '',
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'version' => (string) config('app.version', '1.0.0'),
                'reverb' => [
                    'key' => (string) config('broadcasting.connections.reverb.key'),
                    'host' => (string) config('broadcasting.connections.reverb.options.host'),
                    'port' => (int) config('broadcasting.connections.reverb.options.port'),
                    'scheme' => (string) config('broadcasting.connections.reverb.options.scheme', 'http'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function tenant(Tenant $tenant): array
    {
        $branding = (array) ($tenant->branding ?? []);

        return [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'locale' => $tenant->locale->value,
            'timezone' => $tenant->timezone,
            'logo_url' => self::publicUrl($branding['logo_path'] ?? null),
            'theme' => self::asObject(self::theme($branding)),
            'modules' => $this->modules(),
        ];
    }

    /**
     * CSS custom properties for site.blade.php (`--tenant-<key>`): primary, accent and on_primary when branding has them.
     *
     * @param  array<string, mixed>  $branding
     * @return array<string, string>
     */
    public static function theme(array $branding): array
    {
        $vars = [
            'primary' => $branding['primary_color'] ?? null,
            'accent' => $branding['accent_color'] ?? null,
            'on-primary' => $branding['on_primary_color'] ?? null,
        ];

        return array_filter(
            array_map(fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) === 1 ? $v : null, $vars),
            fn ($v) => $v !== null,
        );
    }

    /** A stored path becomes a URL on the public disk; a full URL passes through; nothing → null. */
    public static function publicUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return preg_match('#^https?://#i', $path) === 1 ? $path : Storage::disk('public')->url($path);
    }

    /**
     * Empty maps must serialise as `{}` (the client types them as Record<string, …>), never as `[]`.
     *
     * @param  array<string, mixed>  $map
     * @return stdClass|array<string, mixed>
     */
    private static function asObject(array $map): stdClass|array
    {
        return $map === [] ? new stdClass : $map;
    }

    /** @return array{guard: string|null, user: array<string, mixed>|null, impersonating: bool} */
    private function auth(Request $request): array
    {
        $name = (string) $request->route()?->getName();
        $impersonating = $request->hasSession() && $request->session()->has('impersonated_by');

        if (str_starts_with($name, 'super.')) {
            $super = $request->user('super');

            return [
                'guard' => $super instanceof SuperAdmin ? 'super' : null,
                'user' => $super instanceof SuperAdmin ? ['id' => $super->id, 'name' => $super->name, 'roles' => ['super_admin'], 'permissions' => [], 'doctor_id' => null] : null,
                'impersonating' => false,
            ];
        }

        if (str_starts_with($name, 'site.portal.') && Tenancy::check()) {
            $patientId = Auth::guard('patient')->id();

            return [
                'guard' => $patientId !== null ? 'patient' : null,
                'user' => $patientId === null ? null : ['id' => (int) $patientId, 'name' => (string) data_get(Auth::guard('patient')->user(), 'name', ''), 'roles' => [], 'permissions' => [], 'doctor_id' => null],
                'impersonating' => false,
            ];
        }

        $user = Tenancy::check() ? $request->user('web') : null;

        if (! $user instanceof User) {
            return ['guard' => null, 'user' => null, 'impersonating' => false];
        }

        return [
            'guard' => 'web',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'doctor_id' => $user->doctor()->value('id'),
            ],
            'impersonating' => $impersonating,
        ];
    }

    /** @return array{id: int, name: string, code: string}|null */
    private function branch(): ?array
    {
        $branch = $this->activeBranch->current();

        return $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function branches(Request $request): array
    {
        if (! Tenancy::check() || ! $request->user('web') instanceof User) {
            return [];
        }

        return Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])
            ->all();
    }

    /** @return array<string, bool> */
    private function features(): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return [];
        }

        return collect(Feature::for($tenant)->all())->map(fn ($v) => (bool) $v)->all();
    }

    /** @return array<int, string> */
    private function modules(): array
    {
        return array_keys(array_filter($this->features()));
    }

    private function ziggyGroup(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_starts_with($name, 'super.') => 'super',
            str_starts_with($name, 'panel.') => 'panel',
            default => 'site',
        };
    }
}
