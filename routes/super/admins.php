<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor;
use App\Http\Controllers\Super\AdminController;
use App\Http\Controllers\Super\Admins\AdminAccessController;
use App\Http\Controllers\Super\Auth\SetPasswordController;
use App\Http\Controllers\Super\Profile\SessionController;
use App\Http\Controllers\Super\ProfileController;
use Illuminate\Support\Facades\Route;

// Platform operators and the operator's own account, on super.{central}. Super admins have no public_id
// (SCHEMA §2.8), so `{admin}` binds by id — a staff-only configuration row in a panel URL (CONVENTIONS §5).
// Credential-grade writes (a new account, a colleague's second factor, a delete, a password) re-ask the acting
// operator's password in their form requests and are throttled like the login they resemble, in their own bucket
// so the console's heartbeat cannot spend it (see `api/ping` in saas.php).
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('admins')->name('admins.')->group(function (): void {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::post('/', [AdminController::class, 'store'])->middleware('throttle:10,1,super-admins')->name('store');
    Route::get('{admin}/edit', [AdminController::class, 'edit'])->whereNumber('admin')->name('edit');
    Route::put('{admin}', [AdminController::class, 'update'])->whereNumber('admin')->middleware('throttle:10,1,super-admins')->name('update');
    Route::delete('{admin}', [AdminController::class, 'destroy'])->whereNumber('admin')->middleware('throttle:10,1,super-admins')->name('destroy');

    Route::post('{admin}/deactivate', [AdminAccessController::class, 'deactivate'])->whereNumber('admin')->name('deactivate');
    Route::post('{admin}/reactivate', [AdminAccessController::class, 'reactivate'])->whereNumber('admin')->name('reactivate');
    Route::post('{admin}/two-factor/reset', [AdminAccessController::class, 'resetTwoFactor'])->whereNumber('admin')->middleware('throttle:10,1,super-admins')->name('two-factor.reset');
    Route::post('{admin}/password-link', [AdminAccessController::class, 'sendPasswordLink'])->whereNumber('admin')->middleware('throttle:5,1,super-admins')->name('password-link');
});

Route::middleware(EnsureSuperAdminIsActive::class)->prefix('profile')->name('profile.')->group(function (): void {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::put('/', [ProfileController::class, 'update'])->middleware('throttle:10,1,super-profile')->name('update');
    Route::put('password', [ProfileController::class, 'password'])->middleware('throttle:10,1,super-profile')->name('password');
    Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy_others');
    Route::delete('sessions/{ref}', [SessionController::class, 'destroy'])->where('ref', '[a-f0-9]{32}')->name('sessions.destroy');
});

// Where a mailed set-password link lands (SendSuperSetPasswordLink → broker `super_admins`). Guest routes, like the
// login pair: they run before anyone is authenticated and opt out of the group's auth and two-factor gate.
Route::get('set-password/{token}', [SetPasswordController::class, 'create'])
    ->middleware('guest:super')->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('password.set');
Route::post('set-password', [SetPasswordController::class, 'store'])
    ->middleware(['guest:super', 'throttle:10,1,super-set-password'])->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])->name('password.store');
