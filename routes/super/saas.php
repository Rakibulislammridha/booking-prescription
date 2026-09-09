<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor;
use App\Http\Controllers\Api\Tenancy\PingController;
use App\Http\Controllers\Super\AuditController;
use App\Http\Controllers\Super\Catalog\ReconciliationController;
use App\Http\Controllers\Super\Catalog\ReviewController;
use App\Http\Controllers\Super\PlanController;
use App\Http\Controllers\Super\UsageController;
use Illuminate\Support\Facades\Route;

// Platform-wide screens on super.{central}: plans, usage, audit and the catalog review queues.
// The group already carries ['web', 'central', 'auth:super'] from bootstrap/app.php; EnsureSuperAdminIsActive
// adds the kill switch (a deactivated operator is logged out on their next request) and asserts no tenancy.
// The panel bundle's connection heartbeat (OFFLINE.md §3.2) fetches `/api/ping` on whatever host it is running
// on. `routes/api/*` is registered inside the `tenant` group, so on super.{central} that ping 404s — which the
// ConnectionIndicator faithfully reports as a red OFFLINE bar across a console that is perfectly online, and logs
// a failed request on every beat. The super host answers the same tiny document instead (tenant: null); it is
// registered before the api group by bootstrap/app.php's ordering, and opts out of auth AND of the two-factor
// gate because a heartbeat must answer whether or not anybody is signed in — an operator held on the enrolment
// screen is online, and a 403 there would paint that screen with the same false OFFLINE bar.
Route::get('api/ping', PingController::class)
    ->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])
    ->middleware('throttle:60,1')->name('ping');

Route::middleware(EnsureSuperAdminIsActive::class)->group(function (): void {
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('plans/{plan:code}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan:code}', [PlanController::class, 'destroy'])->name('plans.destroy');

    Route::get('usage', UsageController::class)->name('usage.index');
    Route::get('audit', AuditController::class)->name('audit.index');

    Route::get('catalog/review', ReviewController::class)->name('catalog.review');
    Route::get('catalog/reconciliation', [ReconciliationController::class, 'index'])->name('catalog.reconciliation.index');
    Route::post('catalog/reconciliation/{report}/resolve', [ReconciliationController::class, 'resolve'])->name('catalog.reconciliation.resolve');
});
