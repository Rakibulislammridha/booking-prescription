<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Actions\Settings\UpdatePlatformSetting;
use App\Domain\SaaS\Queries\PlatformSettingsScreen;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Settings\UpdatePlatformSettingRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform settings (`public.platform_settings`, SCHEMA §2.19) on super.{central}: the registry rendered as a
 * screen, one key saved at a time. The first key is the console's own second-factor policy (ARCHITECTURE §6.5),
 * which is why this screen is one of the two an operator held on forced enrolment may still open.
 */
final class PlatformSettingController extends Controller
{
    public function index(PlatformSettingsScreen $screen): Response
    {
        return Inertia::render('Super/Settings/Index', ['groups' => $screen->groups()]);
    }

    public function update(UpdatePlatformSettingRequest $request, string $key, UpdatePlatformSetting $update): RedirectResponse
    {
        $update->handle($key, $request->value(), $request->admin());

        return redirect()->route('super.settings.index')
            ->with('flash.success', __('super.settings.flash.saved', ['label' => __("super.settings.{$key}.label")]));
    }
}
