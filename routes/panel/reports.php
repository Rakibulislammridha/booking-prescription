<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportKind;
use App\Http\Controllers\Panel\Reports\DashboardController;
use App\Http\Controllers\Panel\Reports\ExportArchiveController;
use App\Http\Controllers\Panel\Reports\ExportController;
use App\Http\Controllers\Panel\Reports\ReportController;
use Illuminate\Support\Facades\Route;

// Reports & analytics (engineer R, names panel.reports.*) — BRIEF §5.L. The dashboard is the section's index
// (the nav entry PanelLayout already carries points here); every other family is `{report}`, constrained to the
// ReportKind values so an unknown segment 404s at the router rather than inside a controller.
//
// Authorisation is per report through the `reports.open` / `reports.export` gates (ReportsServiceProvider):
// money needs `reports.financial.view`, clinical detail needs `reports.clinical.view`, and a user without
// `reports.all-doctors.view` has the doctor filter forced to his own doctor row before any query runs.
Route::prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('index');

    Route::get('exports', [ExportArchiveController::class, 'index'])->name('exports.index');
    Route::get('exports/{export:public_id}', [ExportArchiveController::class, 'show'])->name('exports.show');

    $kinds = implode('|', array_filter(ReportKind::values(), fn (string $v): bool => $v !== ReportKind::Dashboard->value));

    Route::get('{report}/export', ExportController::class)->where('report', $kinds.'|'.ReportKind::Dashboard->value)->name('export');
    Route::get('{report}', [ReportController::class, 'show'])->where('report', $kinds)->name('show');
});
