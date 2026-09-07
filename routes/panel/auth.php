<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Auth\LoginController;
use App\Http\Controllers\Panel\Auth\NewPasswordController;
use App\Http\Controllers\Panel\Auth\PasswordResetLinkController;
use App\Http\Controllers\Panel\BranchSwitchController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\LocaleController;
use App\Http\Middleware\SetActiveBranch;
use Illuminate\Support\Facades\Route;

// Staff session (guard web) on the tenant host. Guest-facing routes opt out of the group's auth:web.
Route::middleware('guest:web')->withoutMiddleware(['auth:web', SetActiveBranch::class])->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');

    // Password reset (broker `users`, tenant-schema password_reset_tokens); the link in the e-mail is panel.password.reset.
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.store');
});

// Language switch works for guests (login page) and staff alike.
Route::patch('locale', LocaleController::class)->withoutMiddleware(['auth:web', SetActiveBranch::class])->name('locale');

Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
Route::patch('branch', BranchSwitchController::class)->name('branch.switch');

Route::get('/', DashboardController::class)->name('dashboard');
