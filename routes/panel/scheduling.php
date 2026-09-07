<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Scheduling\OverrideController;
use App\Http\Controllers\Panel\Scheduling\ScheduleController;
use App\Http\Controllers\Panel\Scheduling\SessionController;
use Illuminate\Support\Facades\Route;

// Scheduling (engineer S): weekly templates, per-date overrides, session-day view. Inertia pages + form posts.
Route::get('scheduling', [ScheduleController::class, 'index'])->name('scheduling.index');
Route::post('scheduling/schedules', [ScheduleController::class, 'store'])->name('scheduling.schedules.store');
Route::put('scheduling/schedules/{schedule}', [ScheduleController::class, 'update'])->name('scheduling.schedules.update');
Route::delete('scheduling/schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('scheduling.schedules.destroy');
Route::post('scheduling/overrides', [OverrideController::class, 'store'])->name('scheduling.overrides.store');
Route::delete('scheduling/overrides/{override}', [OverrideController::class, 'destroy'])->name('scheduling.overrides.destroy');
Route::get('scheduling/sessions', [SessionController::class, 'index'])->name('scheduling.sessions.index');
