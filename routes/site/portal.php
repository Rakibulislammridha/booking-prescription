<?php

declare(strict_types=1);

use App\Http\Controllers\Site\Portal\ActingForController;
use App\Http\Controllers\Site\Portal\HomeController;
use App\Http\Controllers\Site\Portal\OtpController;
use Illuminate\Support\Facades\Route;

// Patient portal (guard `patient`, ARCHITECTURE §6.3), names site.portal.*. Login is mobile + OTP; the throttle:otp
// limiter is registered by PatientsServiceProvider. bootstrap/app.php redirects guests of site.portal.* to site.portal.login.
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::get('login', [OtpController::class, 'create'])->middleware('guest:patient')->name('login');
    Route::post('otp', [OtpController::class, 'request'])->middleware(['guest:patient', 'throttle:otp'])->name('otp.request');
    Route::get('verify', [OtpController::class, 'verifyForm'])->middleware('guest:patient')->name('verify');
    Route::post('otp/verify', [OtpController::class, 'verify'])->middleware(['guest:patient', 'throttle:otp'])->name('otp.verify');

    Route::get('/', HomeController::class)->middleware('auth:patient')->name('home');
    Route::patch('acting-for', ActingForController::class)->middleware('auth:patient')->name('acting_for');
    Route::post('logout', [OtpController::class, 'destroy'])->middleware('auth:patient')->name('logout');
});
