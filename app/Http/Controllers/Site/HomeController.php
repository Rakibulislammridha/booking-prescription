<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET / on a tenant host (site.home): the public landing page. Tenant identity/branding comes from the shared props
 * (HandleInertiaRequests::tenant); the Booking module adds the doctor grid.
 */
final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home/Index');
    }
}
