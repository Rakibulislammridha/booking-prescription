<?php

declare(strict_types=1);

use App\Http\Controllers\Site\Booking\BookingController;
use App\Http\Controllers\Site\Booking\KioskController;
use App\Http\Controllers\Site\Booking\OtpController;
use Illuminate\Support\Facades\Route;

// Public booking site (BRIEF §5.C, names site.booking.*): search → doctor calendar → mobile/OTP → confirmation;
// the kiosk QR (signed, 12 h) lands on the same doctor page prefilled (SERIAL_ENGINE §11.2).
Route::prefix('booking')->name('booking.')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->name('index');
    Route::get('kiosk', KioskController::class)->middleware(['signed', 'throttle:kiosk'])->name('kiosk');
    Route::post('otp', OtpController::class)->middleware('throttle:otp')->name('otp');
    Route::post('/', [BookingController::class, 'store'])->middleware('throttle:booking')->name('store');
    Route::get('confirmed/{appointment:public_id}', [BookingController::class, 'confirmed'])->name('confirmed');
    Route::get('{doctor}', [BookingController::class, 'doctor'])->name('doctor');
});
