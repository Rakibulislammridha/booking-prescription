<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor;
use App\Http\Controllers\Super\Auth\LoginController;
use App\Http\Controllers\Super\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Super\Auth\TwoFactorController;
use App\Http\Controllers\Super\DashboardController;
use Illuminate\Support\Facades\Route;

// Super-admin session (guard super) on super.{central}. The login pair and the TOTP challenge opt out of the
// group's auth:super — they run BEFORE anyone is authenticated — and the enrolment screen opts out of
// EnsureSuperTwoFactor, because it is where the middleware sends an operator who has not enrolled yet.
Route::get('login', [LoginController::class, 'create'])->middleware('guest:super')->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware(['guest:super', 'throttle:10,1'])->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('login.store');

// The password half is done; the operator is still a guest until a code lands. `throttle:super-2fa` is the
// per-IP outer wall; the per-admin lockout that actually matters is inside the controller (SaaSServiceProvider).
Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'create'])
    ->middleware('guest:super')->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('two-factor.challenge');
Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware(['guest:super', 'throttle:super-2fa'])->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('two-factor.challenge.store');

// Logging out must work from inside forced enrolment: otherwise an operator who cannot enrol right now is stuck
// on one screen with no way to end their session.
Route::post('logout', [LoginController::class, 'destroy'])->withoutMiddleware(EnsureSuperTwoFactor::class)->name('logout');

// EnsureSuperTwoFactor stays ON this group (B4): an enrolled operator whose session never passed the challenge is
// logged out before it can reach these routes; an operator still enrolling is let through by the middleware's
// forced-enrolment branch (it recognises `super.two-factor.*`). regenerating recovery codes and disabling the
// factor each re-ask for the password AND a current TOTP code, in their form requests.
Route::prefix('security/two-factor')->name('two-factor.')->group(function (): void {
    Route::get('/', [TwoFactorController::class, 'show'])->name('show');
    Route::post('/', [TwoFactorController::class, 'store'])->name('store');
    Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:super-2fa')->name('confirm');
    Route::post('recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:super-2fa')->name('recovery-codes');
    Route::delete('/', [TwoFactorController::class, 'destroy'])->middleware('throttle:super-2fa')->name('destroy');
});

Route::get('/', DashboardController::class)->name('dashboard');
