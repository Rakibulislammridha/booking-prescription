<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetTenantLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale = $sessionLocale ?? Tenancy::current()?->locale->value ?? 'bn';

        app()->setLocale(in_array($locale, ['bn', 'en'], true) ? $locale : 'bn');

        return $next($request);
    }
}
