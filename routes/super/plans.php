<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\PlanController;
use App\Http\Controllers\Super\Plans\ClonePlanController;
use Illuminate\Support\Facades\Route;

// Plan management on super.{central} (BRIEF §5.M). Plans bind by `code` — a stable, human-readable key that
// already appears in invoices and the audit log. The list, a full-page editor (create/edit), archive/restore
// through DELETE (`restore=1` un-archives; `confirm=1` is the guard for a plan with live subscribers) and
// "clone plan", which lands in the copy's editor.
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('plans')->name('plans.')->group(function (): void {
    Route::get('/', [PlanController::class, 'index'])->name('index');
    Route::get('create', [PlanController::class, 'create'])->name('create');
    Route::post('/', [PlanController::class, 'store'])->name('store');
    Route::get('{plan:code}/edit', [PlanController::class, 'edit'])->name('edit');
    Route::put('{plan:code}', [PlanController::class, 'update'])->name('update');
    Route::delete('{plan:code}', [PlanController::class, 'destroy'])->name('destroy');
    Route::post('{plan:code}/clone', ClonePlanController::class)->name('clone');
});
