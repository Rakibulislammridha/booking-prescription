<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\SwitchLocaleRequest;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;

/**
 * PATCH /panel/locale (panel.locale): session locale for staff and guests of the panel (login page too); a logged-in
 * user's preference is also stored on users.locale so it follows them to the next device.
 */
final class LocaleController extends Controller
{
    public function __invoke(SwitchLocaleRequest $request): RedirectResponse
    {
        $locale = $request->locale();
        $request->session()->put('locale', $locale->value);

        $user = $request->user('web');

        if ($user instanceof User && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return back(fallback: route('panel.dashboard', absolute: false));
    }
}
