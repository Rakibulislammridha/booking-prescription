<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Language switch for the marketing surface. The tenant version (`site.locale`) needs a tenant; this one is for
 * the central host, where there is none, so the choice lives in the session only.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(['bn', 'en'])]]);
        $request->session()->put('locale', $validated['locale']);

        return back();
    }
}
