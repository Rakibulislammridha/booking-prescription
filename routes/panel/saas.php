<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Auth\ImpersonationController;
use App\Http\Controllers\Panel\SaaS\DomainController;
use App\Http\Controllers\Panel\SaaS\SubscriptionController;
use App\Http\Middleware\SetActiveBranch;
use Illuminate\Support\Facades\Route;

// Impersonation hand-off (ARCHITECTURE §6.5). `enter` opts OUT of auth:web — nobody is signed in yet, and the
// 60-second single-use token IS the credential — and out of SetActiveBranch, which needs a user.
Route::prefix('impersonate')->name('impersonate.')->group(function (): void {
    Route::get('{token}', [ImpersonationController::class, 'enter'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->withoutMiddleware(['auth:web', SetActiveBranch::class])
        ->middleware('throttle:20,1')
        ->name('enter');
    Route::get('started', [ImpersonationController::class, 'started'])->name('started');
    Route::post('leave', [ImpersonationController::class, 'leave'])->name('leave');
});

// The clinic's own subscription and custom-domain screens (permission saas.settings.manage).
Route::prefix('subscription')->name('saas.subscription.')->group(function (): void {
    Route::get('/', [SubscriptionController::class, 'index'])->name('index');
});

Route::prefix('domains')->name('saas.domains.')->group(function (): void {
    Route::get('/', [DomainController::class, 'index'])->name('index');
    Route::post('/', [DomainController::class, 'store'])->name('store');
    Route::post('{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:saas-domain-verify')->name('verify');
    Route::post('{domain}/primary', [DomainController::class, 'primary'])->name('primary');
    Route::delete('{domain}', [DomainController::class, 'destroy'])->name('destroy');
});
