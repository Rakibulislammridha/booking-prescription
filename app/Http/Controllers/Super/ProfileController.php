<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Clinic\Data\StaffSessionData;
use App\Domain\SaaS\Actions\Profile\ChangeSuperPassword;
use App\Domain\SaaS\Actions\Profile\UpdateSuperProfile;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Profile\ChangePasswordRequest;
use App\Http\Requests\Super\Profile\UpdateProfileRequest;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's own account (top-right menu): name and email, the password, the devices this account is signed
 * in on, and a pointer to the Security tab for the second factor. Everything here acts on `$request->user('super')`
 * and never on an id from the request.
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly SuperSessionIndex $sessions,
        private readonly SuperTwoFactor $twoFactor,
        private readonly PlatformSettings $settings,
    ) {}

    public function show(Request $request): Response
    {
        $admin = $this->admin($request);

        return Inertia::render('Super/Profile/Show', [
            'profile' => [
                'name' => $admin->name,
                'email' => $admin->email,
                'two_factor' => $this->twoFactor->enabled($admin) ? 'enabled' : ($this->twoFactor->pendingSecret($admin) !== null ? 'enrolling' : 'none'),
                'two_factor_policy' => $this->twoFactor->policy()->value,
                'recovery_codes' => $this->twoFactor->recoveryCodesRemaining($admin),
                'last_login_at' => $admin->last_login_at?->toIso8601String(),
                'last_login_ip' => $admin->last_login_ip,
                'created_at' => $admin->created_at?->toIso8601String(),
            ],
            'sessions' => array_map(fn (StaffSessionData $s) => $s->toArray(), $this->sessions->all($admin)),
            'idle_timeout_minutes' => $this->idleMinutes(),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateSuperProfile $update): RedirectResponse
    {
        $update->handle($request->admin(), trim((string) $request->string('name')), (string) $request->string('email'));

        return redirect()->route('super.profile.show')->with('flash.success', __('super.profile.flash.saved'));
    }

    public function password(ChangePasswordRequest $request, ChangeSuperPassword $change): RedirectResponse
    {
        $revoked = $change->handle($request->admin(), $request->password(), $request->session()->getId());

        return redirect()->route('super.profile.show')->with('flash.success', __('super.profile.flash.password_changed', ['count' => (string) $revoked]));
    }

    /**
     * What EnforceIdleTimeout applies to the super guard: the platform setting `security.console_idle_minutes`
     * when the registry knows it (read by key, so this screen does not depend on the registry's constants),
     * else the config value it defaults from.
     */
    private function idleMinutes(): int
    {
        $key = 'security.console_idle_minutes';

        if (PlatformSettingsRegistry::has($key)) {
            return max(0, (int) $this->settings->get($key));
        }

        return max(0, (int) config('session.idle_timeout_minutes', (int) config('session.lifetime')));
    }

    private function admin(Request $request): SuperAdmin
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
