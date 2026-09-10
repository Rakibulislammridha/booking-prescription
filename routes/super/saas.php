<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Domain\SaaS\Http\Middleware\EnsureSuperTwoFactor;
use App\Http\Controllers\Api\Tenancy\PingController;
use App\Http\Controllers\Super\AuditController;
use App\Http\Controllers\Super\AuditExportController;
use App\Http\Controllers\Super\Catalog\ReconciliationController;
use App\Http\Controllers\Super\Catalog\ReviewController;
use App\Http\Controllers\Super\Usage\TenantUsageController;
use App\Http\Controllers\Super\Usage\UsageExportController;
use App\Http\Controllers\Super\UsageController;
use Illuminate\Support\Facades\Route;

// Platform-wide screens on super.{central}: usage, audit and the catalog review queues.
// The group already carries ['web', 'central', 'auth:super'] from bootstrap/app.php; EnsureSuperAdminIsActive
// adds the kill switch (a deactivated operator is logged out on their next request) and asserts no tenancy.
// The panel bundle's connection heartbeat (OFFLINE.md §3.2) fetches `/api/ping` on whatever host it is running
// on. `routes/api/*` is registered inside the `tenant` group, so on super.{central} that ping 404s — which the
// ConnectionIndicator faithfully reports as a red OFFLINE bar across a console that is perfectly online, and logs
// a failed request on every beat. The super host answers the same tiny document instead (tenant: null); it is
// registered before the api group by bootstrap/app.php's ordering, and opts out of auth AND of the two-factor
// gate because a heartbeat must answer whether or not anybody is signed in — an operator held on the enrolment
// screen is online, and a 403 there would paint that screen with the same false OFFLINE bar.
// The limiter carries its own prefix on purpose: a numeric `throttle:N,M` keys a GUEST by `sha1(domain|ip)` and a
// signed-in operator by their id, whatever the route — so without a prefix the heartbeat (one hit every five
// seconds on the login screen) shares one counter with `throttle:10,1` on `POST login`, and an operator who sat on
// the login page for a minute got 429 on the password they then typed.
Route::get('api/ping', PingController::class)
    ->withoutMiddleware(['auth:super', EnsureSuperTwoFactor::class])
    ->middleware('throttle:60,1,super-ping')->name('ping');

// Plans moved to routes/super/plans.php; the platform billing desk is routes/super/billing.php.
Route::middleware(EnsureSuperAdminIsActive::class)->group(function (): void {
    Route::get('usage', UsageController::class)->name('usage.index');
    Route::get('usage/export', UsageExportController::class)->name('usage.export');
    Route::get('usage/{tenant:public_id}', TenantUsageController::class)->name('usage.show');
    Route::get('audit', AuditController::class)->name('audit.index');
    Route::get('audit/export', AuditExportController::class)->name('audit.export');

    Route::get('catalog/review', ReviewController::class)->name('catalog.review');
    Route::get('catalog/reconciliation', [ReconciliationController::class, 'index'])->name('catalog.reconciliation.index');
    Route::post('catalog/reconciliation/{report}/resolve', [ReconciliationController::class, 'resolve'])->name('catalog.reconciliation.resolve');
});
