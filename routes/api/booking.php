<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Booking\PublicBookingController;
use Illuminate\Support\Facades\Route;

// POST /api/public/bookings — the public booking API (SERIAL_ENGINE §16, api.booking.public.store, no auth).
Route::post('public/bookings', PublicBookingController::class)->middleware('throttle:booking')->name('booking.public.store');
