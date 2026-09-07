<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page after super-admin login (panel page Super/Dashboard). The SaaS module owns the real dashboard.
 */
final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Super/Dashboard', [
            'tenants_count' => Tenant::query()->count(),
        ]);
    }
}
