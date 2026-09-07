<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Scheduling\AvailabilityController;
use Illuminate\Support\Facades\Route;

// GET /api/public/doctors/{slug}/availability?from&to&branch — the public booking calendar (no auth; throttle:api applies).
Route::get('public/doctors/{slug}/availability', AvailabilityController::class)->name('scheduling.availability');
