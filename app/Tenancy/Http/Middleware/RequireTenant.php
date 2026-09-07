<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Tenancy::check(), 404);

        return $next($request);
    }
}
