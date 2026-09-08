<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\SetCentralLocale;
use App\Http\Controllers\Central\OnboardingController;
use Illuminate\Support\Facades\Route;

// Tenant sign-up wizard (BRIEF §5.M). The POST provisions a Postgres schema, so it is throttled per IP by the
// `saas-signup` limiter registered in SaaSServiceProvider — it is the most expensive anonymous request we serve.
Route::middleware(SetCentralLocale::class)->group(function (): void {
    Route::get('signup', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('signup', [OnboardingController::class, 'store'])->middleware('throttle:saas-signup')->name('onboarding.store');
    Route::get('welcome/{tenant:public_id}', [OnboardingController::class, 'done'])->name('onboarding.done');
});
