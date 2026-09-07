<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\SwitchLocaleRequest;
use Illuminate\Http\RedirectResponse;

/** PATCH /locale (site.locale): remembers the visitor's language in the session; SetTenantLocale reads it. */
final class LocaleController extends Controller
{
    public function __invoke(SwitchLocaleRequest $request): RedirectResponse
    {
        $request->session()->put('locale', $request->locale()->value);

        return back(fallback: route('site.home', absolute: false));
    }
}
