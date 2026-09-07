<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page after staff login (panel page Dashboard/Index). Module widgets are added by their owners.
 */
final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard/Index');
    }
}
