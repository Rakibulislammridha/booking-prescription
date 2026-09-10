<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\Catalog\BrowserController;
use App\Http\Controllers\Super\Catalog\ImportController;
use App\Http\Controllers\Super\Catalog\MaintenanceController;
use App\Http\Controllers\Super\Catalog\PromotionController;
use App\Http\Controllers\Super\Catalog\ReconciliationController;
use Illuminate\Support\Facades\Route;

// The shared clinical catalogue from the console (BRIEF §3.2 "updated centrally when DGDA publishes"): the
// browser with its read-only drawer and the few edits that are safe centrally, bundle imports as Horizon jobs,
// the search-index rebuild and the reconciliation sweep, and the custom-brand promotion queue (CATALOG.md §8).
// The group already carries ['web', 'central', 'auth:super', 'idle:super', EnsureSuperTwoFactor] from
// bootstrap/app.php. Catalogue rows have no public_id (SCHEMA §4) — their bigint ids appear here as on every
// staff-only configuration screen (CONVENTIONS §5); promotions and jobs bind by public_id.
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('catalog')->name('catalog.')->group(function (): void {
    Route::get('/', [BrowserController::class, 'index'])->name('index');

    // Bundle imports (CATALOG.md §5) — never run in the request: upload → dry run / apply as queued jobs.
    Route::prefix('imports')->name('imports.')->group(function (): void {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('{job:public_id}', [ImportController::class, 'show'])->name('show');
        Route::post('{job:public_id}/dry-run', [ImportController::class, 'dryRun'])->name('dry-run');
        Route::post('{job:public_id}/apply', [ImportController::class, 'apply'])->name('apply');
        Route::delete('{job:public_id}', [ImportController::class, 'destroy'])->name('destroy');
    });

    Route::post('index/rebuild', [MaintenanceController::class, 'reindex'])->name('index.rebuild');
    Route::post('reconcile/run', [MaintenanceController::class, 'reconcile'])->name('reconcile.run');

    // Reconciliation reports (SCHEMA §2.12): list with filters, one report with its orphan sample, notify the clinic.
    Route::prefix('reconciliation')->name('reconciliation.')->group(function (): void {
        Route::get('{report}/detail', [ReconciliationController::class, 'show'])->whereNumber('report')->name('show');
        Route::post('{report}/notify', [ReconciliationController::class, 'notify'])->whereNumber('report')->name('notify');
    });

    // Custom-brand promotion review queue (CATALOG.md §8); promotions bind by public_id (ULID). JSON endpoints.
    Route::prefix('promotions')->name('promotions.')->group(function (): void {
        Route::get('/', [PromotionController::class, 'index'])->name('index');
        Route::get('tenants', [PromotionController::class, 'tenants'])->name('tenants');
        Route::get('{promotion:public_id}', [PromotionController::class, 'show'])->name('show');
        Route::post('{promotion:public_id}/approve', [PromotionController::class, 'approve'])->name('approve');
        Route::post('{promotion:public_id}/reject', [PromotionController::class, 'reject'])->name('reject');
    });

    // The browser's drawer (JSON) and the inline edits that are safe centrally — every one through
    // CatalogWriteContext, audited, followed by a search upsert. Registered last: `{kind}` is a closed list.
    Route::get('{kind}/{id}', [BrowserController::class, 'show'])->whereIn('kind', BrowserController::KINDS)->whereNumber('id')->name('show');
    Route::put('{kind}/{id}/active', [BrowserController::class, 'active'])->whereIn('kind', BrowserController::KINDS)->whereNumber('id')->name('active');
    Route::put('generics/{id}/information', [BrowserController::class, 'information'])->whereNumber('id')->name('information');
    Route::put('icd10/{id}/aliases', [BrowserController::class, 'aliases'])->whereNumber('id')->name('icd10.aliases');
});
