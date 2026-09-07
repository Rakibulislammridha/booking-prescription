<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Reports\DashboardController;
use Illuminate\Support\Facades\Route;

// The dashboard's refresh poll (engineer R, names api.reports.*). Staff session only — this is the panel page
// keeping its tiles current while the owner leaves it open, not a third-party surface, so it rides the same
// `web` guard as the page that calls it rather than a device token.
Route::middleware('auth:web')->get('reports/dashboard', DashboardController::class)->name('reports.dashboard');
